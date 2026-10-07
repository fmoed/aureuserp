<?php

namespace Webkul\BiometricAttendance\Services;

use Illuminate\Support\Facades\Log;
use Mithun\PhpZkteco\Libs\ZKTeco;
use Throwable;
use Webkul\BiometricAttendance\Contracts\ZktecoClientInterface;
use Webkul\BiometricAttendance\Models\Device;

/**
 * Thin adapter over the candidate 0mithun/php-zkteco library.
 *
 * Candidate status: must be proven against the real device (Phase 1) before
 * the rest of the integration is built on it. Swap by implementing
 * ZktecoClientInterface if the device proves it unsuitable.
 *
 * Only read-only device calls are used. State-changing calls (clearAttendance,
 * restart, shutdown, setTime, user management) are never invoked here.
 */
class PhpZktecoClient implements ZktecoClientInterface
{
    /** @var ?callable(string): void */
    protected mixed $progress = null;

    /**
     * Live stage reporter used by --debug. Never receives secrets.
     */
    public function setProgress(?callable $progress): void
    {
        $this->progress = $progress;
    }

    public function getDeviceInfo(Device $device): DeviceInfo
    {
        $started = microtime(true);
        $this->report("reading device information from [{$device->host}]");
        $client = $this->connect($device);

        try {
            $info = new DeviceInfo(
                deviceName: $this->stringOrNull($client->deviceName()),
                serialNumber: $this->stringOrNull($client->serialNumber()),
                firmware: $this->stringOrNull($client->version()),
                platform: $this->stringOrNull($client->platform()),
                vendorName: $this->stringOrNull($client->vendorName()),
                osVersion: $this->stringOrNull($client->osVersion()),
                deviceTime: $this->stringOrNull($client->getTime()),
                host: $device->host,
            );
            Log::debug('biometric device info read', [
                'host' => $device->host, 'duration_ms' => (int) ((microtime(true) - $started) * 1000),
            ]);

            return $info;
        } catch (Throwable $e) {
            Log::debug('biometric device info failed', ['host' => $device->host, 'error' => $e->getMessage()]);

            throw new ZktecoConnectionException('Failed reading device information: '.$e->getMessage(), previous: $e);
        } finally {
            $this->safeDisconnect($client);
        }
    }

    public function getAttendanceLogs(Device $device): array
    {
        $started = microtime(true);
        $this->report("downloading attendance logs from [{$device->host}]");
        $client = $this->connect($device);

        try {
            $records = $client->getAttendances();
        } catch (Throwable $e) {
            Log::debug('biometric attendance read failed', ['host' => $device->host, 'error' => $e->getMessage()]);

            throw new ZktecoConnectionException('Failed reading attendance logs: '.$e->getMessage(), previous: $e);
        } finally {
            $this->safeDisconnect($client);
        }

        if (! is_array($records)) {
            throw new ZktecoConnectionException('Unexpected attendance payload from device.');
        }

        $parsed = array_values(array_map(
            fn (array $record): PunchRecord => PunchRecord::fromArray($record),
            array_filter($records, fn ($record): bool => is_array($record))
        ));

        Log::debug('biometric attendance read', [
            'host'        => $device->host, 'count' => count($parsed),
            'duration_ms' => (int) ((microtime(true) - $started) * 1000),
        ]);

        return $parsed;
    }

    /**
     * @throws ZktecoConnectionException
     */
    protected function connect(Device $device): ZKTeco
    {
        $started = microtime(true);
        $endpoint = "[{$device->host}:{$device->port}] ({$device->protocol})";
        Log::debug('biometric connect attempt', [
            'host'     => $device->host, 'port' => $device->port,
            'protocol' => $device->protocol, 'has_comm_key' => (int) $device->comm_key !== 0,
        ]);

        if ($device->protocol !== 'udp') {
            $this->report("probing TCP {$endpoint}");
            $this->assertTcpReachable($device);
            $this->report('TCP port open, starting ZK handshake');
        } else {
            $this->report("starting ZK handshake over UDP {$endpoint}");
        }

        // NOTE: the vendor client opens the TCP socket eagerly inside its
        // constructor and throws on unreachable hosts, so construction must
        // stay inside the guarded block (not only the connect() call).
        try {
            $client = $this->createVendorClient($device);

            $connected = $client->connect();
        } catch (Throwable $e) {
            Log::debug('biometric connect threw', [
                'host' => $device->host, 'error_class' => $e::class, 'error' => $e->getMessage(),
            ]);

            throw new ZktecoConnectionException(
                "Cannot reach device {$endpoint}: ".$e->getMessage(),
                previous: $e
            );
        }

        if (! $connected) {
            $this->safeDisconnect($client);
            Log::debug('biometric handshake refused', ['host' => $device->host]);

            throw new ZktecoConnectionException(
                "Device {$endpoint} refused the handshake. The network path works but the device rejected the session: verify the protocol (TCP vs UDP) and the COMM key from the device menu (COMM settings, not the admin login password)."
            );
        }

        $this->report('handshake accepted in '.(int) ((microtime(true) - $started) * 1000).' ms');

        return $client;
    }

    /**
     * Separate the network layer from the ZK handshake: a failed probe means
     * IP/port/firewall, while a passed probe followed by handshake failure
     * means protocol or COMM key. UDP is connectionless, so no probe applies.
     *
     * @throws ZktecoConnectionException
     */
    protected function assertTcpReachable(Device $device): void
    {
        $socket = @fsockopen($device->host, $device->port, $errno, $errstr, 3);

        if (! is_resource($socket)) {
            throw new ZktecoConnectionException(
                "Cannot reach device [{$device->host}:{$device->port}] (tcp): network layer failed ({$errstr}). Check the IP, that port 4370 is open, and that this server is on the same LAN/VPN as the device."
            );
        }

        fclose($socket);
    }

    protected function createVendorClient(Device $device): ZKTeco
    {
        return new ZKTeco(
            host: $device->host,
            port: $device->port,
            shouldPing: false,
            timeout: 25,
            password: $device->comm_key,
            protocol: $device->protocol === 'udp' ? 'udp' : 'tcp',
        );
    }

    protected function safeDisconnect(ZKTeco $client): void
    {
        try {
            $client->disconnect();
        } catch (Throwable) {
            //
        }
    }

    protected function report(string $stage): void
    {
        Log::debug('biometric stage: '.$stage);

        if (is_callable($this->progress)) {
            ($this->progress)($stage);
        }
    }

    protected function stringOrNull(mixed $value): ?string
    {
        if ($value === null || $value === false || $value === '') {
            return null;
        }

        if (! is_scalar($value)) {
            return null;
        }

        return trim((string) $value) === '' ? null : trim((string) $value);
    }
}
