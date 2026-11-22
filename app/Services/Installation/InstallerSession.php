<?php

namespace App\Services\Installation;

use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\Crypt;

/**
 * Wizard data held in the (server-side, encrypted) session between steps.
 * Passwords are stored encrypted (database) or hashed (administrator) and
 * are never rendered back into forms.
 */
class InstallerSession
{
    public const STEPS = ['welcome', 'requirements', 'database', 'application', 'administrator', 'license', 'install'];

    protected const KEY = 'installer';

    public function __construct(protected Session $session) {}

    public function complete(string $step): void
    {
        $done = $this->session->get(self::KEY.'.completed', []);
        $done[$step] = true;
        $this->session->put(self::KEY.'.completed', $done);
    }

    public function isComplete(string $step): bool
    {
        return (bool) $this->session->get(self::KEY.'.completed.'.$step, false);
    }

    /**
     * The first step that is not complete yet (or the requested step when
     * all its predecessors are complete).
     */
    public function firstIncompleteBefore(string $step): ?string
    {
        foreach (self::STEPS as $candidate) {
            if ($candidate === $step) {
                return null;
            }

            if (! $this->isComplete($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function put(string $section, array $data): void
    {
        $this->session->put(self::KEY.'.data.'.$section, $data);
    }

    /**
     * @return array<string, mixed>
     */
    public function get(string $section): array
    {
        return (array) $this->session->get(self::KEY.'.data.'.$section, []);
    }

    public function encrypt(?string $value): ?string
    {
        return $value === null || $value === '' ? $value : Crypt::encryptString($value);
    }

    public function decrypt(?string $value): ?string
    {
        return $value === null || $value === '' ? $value : Crypt::decryptString($value);
    }

    /**
     * Invalidate later steps when an earlier one changes.
     */
    public function resetAfter(string $step): void
    {
        $index = array_search($step, self::STEPS, true);
        $done = $this->session->get(self::KEY.'.completed', []);

        foreach (array_slice(self::STEPS, $index + 1) as $later) {
            unset($done[$later]);
        }

        $this->session->put(self::KEY.'.completed', $done);
    }

    public function forget(): void
    {
        $this->session->forget(self::KEY);
    }
}
