<?php

namespace App\Support;

use App\Models\AppSetting;
use Illuminate\Support\Str;

/** Terms and Privacy: Markdown edited in the admin panel (Settings → Legal pages); a draft is used until then. */
final class LegalPages
{
    public const PAGES = ['terms' => 'Terms of Use', 'privacy' => 'Privacy Policy'];

    public static function markdown(string $page): string
    {
        $saved = AppSetting::get("legal.{$page}");

        return is_string($saved) && trim($saved) !== '' ? $saved : self::draft($page);
    }

    public static function draft(string $page): string
    {
        return (string) file_get_contents(resource_path("legal/{$page}.md"));
    }

    public static function isDraft(string $page): bool
    {
        $saved = AppSetting::get("legal.{$page}");

        return ! is_string($saved) || trim($saved) === '';
    }

    /** Safe HTML (raw HTML in the Markdown is escaped). */
    public static function html(string $page): string
    {
        $email = AppSetting::get('legal.contact_email');
        $contact = $email ? "Email [{$email}](mailto:{$email}), or use Help in the Ride Coop app." : 'Use Help in the Ride Coop app to contact us.';
        $md = strtr(self::markdown($page), [
            '{{date}}' => (string) (AppSetting::get("legal.{$page}_updated") ?: now()->format('j F Y')),
            '{{delete_url}}' => url('/account/delete'),
            '{{contact}}' => $contact,
        ]);

        return Str::markdown($md, ['html_input' => 'escape', 'allow_unsafe_links' => false]);
    }
}
