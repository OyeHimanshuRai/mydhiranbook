<?php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * One guard for every form and API call: text containing control bytes (NUL, bell, escape ...) or broken UTF-8 never reaches a
 * controller or Postgres (where it would be a 500). Tab, newline and carriage return stay allowed for multi-line notes. Uploaded files are not inspected.
 */
class RejectBadInput
{
    public function handle(Request $request, Closure $next)
    {
        if ($this->bad($request->all()) || $this->bad($request->query())) {
            throw ValidationException::withMessages(['input' => 'Some text contains characters we cannot store. Remove them and try again.']);
        }

        return $next($request);
    }

    private function bad(mixed $value): bool
    {
        if (is_array($value)) {
            foreach ($value as $k => $v) {
                if ($this->bad((string) $k) || $this->bad($v)) { return true; }
            }
            return false;
        }

        return is_string($value) && ($value !== '') && (! mb_check_encoding($value, 'UTF-8') || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) === 1);
    }
}
