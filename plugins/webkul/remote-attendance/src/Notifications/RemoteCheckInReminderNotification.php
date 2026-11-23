<?php

namespace Webkul\RemoteAttendance\Notifications;

use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Webkul\Attendance\Filament\Resources\AttendanceResource;
use Webkul\RemoteAttendance\Filament\Pages\RemoteCheckIn;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Scopes\CompanyScope;

class RemoteCheckInReminderNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $employeeName,
        public string $workDate,
        public ?string $actionUrl = null,
        public ?string $companyName = null,
        public ?string $companyLogo = null,
        public ?string $companyEmail = null,
    ) {}

    public function via(object $notifiable): array
    {
        $channels = [];

        if (method_exists($notifiable, 'getKey') && $notifiable->getKey() !== null) {
            $channels[] = 'database';
        }

        $email = method_exists($notifiable, 'routeNotificationFor')
            ? $notifiable->routeNotificationFor('mail', $this)
            : ($notifiable->email ?? null);

        if (filled($email)) {
            $channels[] = 'mail';
        }

        return $channels === [] ? ['database'] : $channels;
    }

    public function toDatabase(object $notifiable): array
    {
        $url = $this->resolveUrl();

        $notification = FilamentNotification::make()
            ->title(__('remote-attendance::app.notification.title'))
            ->body(__('remote-attendance::app.notification.body', ['date' => $this->workDate]))
            ->icon('heroicon-o-clock')
            ->iconColor('primary');

        if (filled($url)) {
            $notification->actions([
                Action::make('check_in')
                    ->label(__('remote-attendance::app.notification.action'))
                    ->url($url)
                    ->markAsRead(),
            ]);
        }

        return $notification->getDatabaseMessage();
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = $this->resolveUrl();
        $company = $this->resolveCompanyContext($notifiable);

        $mail = (new MailMessage)
            ->subject(__('remote-attendance::app.mail.subject', ['date' => $this->workDate]))
            ->markdown('remote-attendance::mail.reminder', [
                'greeting'     => __('remote-attendance::app.mail.greeting', ['name' => $this->employeeName]),
                'intro'        => __('remote-attendance::app.mail.intro', ['date' => $this->workDate]),
                'taskReminder' => __('remote-attendance::app.mail.task-reminder'),
                'actionText'   => __('remote-attendance::app.mail.action'),
                'actionUrl'    => $url,
                'companyName'  => $company['name'],
                'companyLogo'  => $company['logo'],
                'headerUrl'    => config('app.url') ?: $url,
            ]);

        if (filled(config('mail.from.address'))) {
            $mail->from((string) config('mail.from.address'), $company['name']);
        }

        if (filled($company['email'])) {
            $mail->replyTo($company['email'], $company['name']);
        }

        return $mail;
    }

    /**
     * @return array{name: string, logo: ?string, email: ?string}
     */
    protected function resolveCompanyContext(?object $notifiable = null): array
    {
        $companyName = $this->companyName ?? null;
        $companyLogo = $this->companyLogo ?? null;
        $companyEmail = $this->companyEmail ?? null;
        $company = null;

        if ($companyName === null && $companyLogo === null && $companyEmail === null) {
            if ($notifiable instanceof User) {
                $company = $notifiable->employee()
                    ->withoutGlobalScope(CompanyScope::class)
                    ->with('company.partner')
                    ->first()
                    ?->company;

                $company ??= $notifiable->defaultCompany()
                    ->withoutGlobalScope(CompanyScope::class)
                    ->with('partner')
                    ->first();
            }

            $company ??= Company::query()
                ->withoutGlobalScope(CompanyScope::class)
                ->with('partner')
                ->first();
        }

        $name = $companyName ?: ($company?->name ?: (string) config('app.name'));
        $email = $companyName === null ? ($companyEmail ?? $company?->email) : $companyEmail;
        $rawLogo = $companyName === null
            ? ($companyLogo ?? ($company?->logo ?: $company?->partner?->avatar))
            : $companyLogo;

        return [
            'name'  => (string) $name,
            'logo'  => filled($rawLogo) ? $this->resolveLogoUrl((string) $rawLogo) : null,
            'email' => filled($email) ? (string) $email : null,
        ];
    }

    protected function resolveLogoUrl(string $logo): ?string
    {
        if (str_starts_with($logo, 'http://') || str_starts_with($logo, 'https://')) {
            return $logo;
        }

        $logo = ltrim($logo, '/');
        $configured = (string) config('filesystems.default', 'public');
        $diskName = $configured === 'local' ? 'public' : $configured;
        $disk = Storage::disk($diskName);

        if (! $disk->exists($logo)) {
            return null;
        }

        $url = $disk->url($logo);

        if (! str_starts_with($url, 'http://') && ! str_starts_with($url, 'https://')) {
            $url = url($url);
        }

        return preg_replace('#(?<!:)/{2,}#', '/', $url) ?? $url;
    }

    protected function resolveUrl(): ?string
    {
        if (filled($this->actionUrl)) {
            return $this->actionUrl;
        }

        if (class_exists(AttendanceResource::class) && Route::has(AttendanceResource::getRouteBaseName().'.index')) {
            return AttendanceResource::getUrl('index');
        }

        if (class_exists(RemoteCheckIn::class) && Route::has(RemoteCheckIn::getRouteName())) {
            return RemoteCheckIn::getUrl();
        }

        return null;
    }
}
