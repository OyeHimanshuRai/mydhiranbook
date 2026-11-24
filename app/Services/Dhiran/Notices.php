<?php

namespace App\Services\Dhiran;

use App\Models\Dhiran\DhiranLoan;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * What needs the shop's attention today (owner 2026-11-24): interest installments coming due, loans nearing forfeiture,
 * loans awaiting evidence. Computed live from the loans (nothing is stored per notice), in ONE query per shop per request.
 * Each row links to the exact list that shows the problem (the loan itself when only one is affected). The same SQL
 * conditions drive the counts and the Loans-list filters, so a click always lands on exactly the loans that were counted.
 */
final class Notices
{
    /** Rows in the order they matter: key => [tone, hint, list filter]. The wording that carries the shop's own day settings comes from text(). */
    private const ROWS = [
        'forfeit_ready'  => ['danger', 'The notice deadline has passed. Review each loan, then forfeit it.', ['forfeit' => 'ready']],
        'forfeit_notice' => ['danger', 'Grace is over. Print the notice and send it to the customer.', ['forfeit' => 'notice']],
        'inst_late'      => ['danger', 'The month is already running. Collect the installment.', ['installment' => 'late']],
        'pending'        => ['warn', 'Add the missing photos or documents to activate the loan.', ['status' => 'pending_evidence']],
        'inst_w1'        => ['warn', 'Remind the customer to pay this month\'s interest.', ['installment' => 'w1']],
        'forfeit_soon'   => ['warn', 'Past maturity soon. Talk to the customer before the notice goes out.', ['forfeit' => 'soon']],
        'inst_w2'        => ['info', 'Interest installments coming up.', ['installment' => 'w2']],
        'inst_w3'        => ['info', 'Interest installments coming up.', ['installment' => 'w3']],
        'mat_w1'         => ['warn', 'The loan period ends. Ask the customer to pay, renew or take the items back.', ['maturity' => 'w1']],
        'mat_w2'         => ['info', 'Loans reaching their maturity date.', ['maturity' => 'w2']],
        'mat_w3'         => ['info', 'Loans reaching their maturity date.', ['maturity' => 'w3']],
    ];

    /** Defaults for a shop's notification settings (dhiran_settings.notices_*). */
    public const DEFAULT_DAYS = [3, 7, 30];
    public const DEFAULT_FORFEIT_DAYS = 30;

    /** @return array{popup: bool, off: list<string>, forfeit_days: int, days: array{0:int,1:int,2:int}} */
    public static function options(int $shopId): array
    {
        $s = \App\Models\Dhiran\DhiranSettings::getForShop($shopId);
        $days = array_map('intval', (array) ($s->notices_interest_days ?: self::DEFAULT_DAYS));
        sort($days);

        return [
            'popup' => (bool) ($s->notices_popup ?? true),
            'off' => array_values((array) ($s->notices_off ?? [])),
            'forfeit_days' => (int) ($s->notices_forfeit_days ?: self::DEFAULT_FORFEIT_DAYS),
            'days' => count($days) === 3 ? $days : self::DEFAULT_DAYS,
        ];
    }

    /** Row key => settings-page label (what the owner switches on or off). */
    public static function toggles(array $o): array
    {
        return array_map(fn ($key) => ucfirst(self::text($key, $o)), array_combine(array_keys(self::ROWS), array_keys(self::ROWS)));
    }

    /** The phrase after "N loans": uses the shop's own day windows. */
    private static function text(string $key, array $o, int $n = 2): string
    {
        [$a, $b, $c] = $o['days'];

        return match ($key) {
            'forfeit_ready' => 'ready to forfeit',
            'forfeit_notice' => $n === 1 ? 'needs a forfeiture notice' : 'need a forfeiture notice',
            'inst_late' => 'with interest installment overdue',
            'pending' => 'waiting for evidence',
            'inst_w1' => "with interest due in $a days",
            'inst_w2' => 'with interest due in '.($a + 1)."–$b days",
            'inst_w3' => 'with interest due in '.($b + 1)."–$c days",
            'forfeit_soon' => "near forfeiture (within {$o['forfeit_days']} days)",
            'mat_w1' => "reaching maturity in $a days",
            'mat_w2' => 'reaching maturity in '.($a + 1)."–$b days",
            'mat_w3' => 'reaching maturity in '.($b + 1)."–$c days",
        };
    }

    /** Chip labels for the Loans-list filters, with the shop's own windows. */
    public static function filterLabels(int $shopId): array
    {
        $o = self::options($shopId);

        return [
            'installment' => ['late' => 'Interest installment late', 'w1' => 'Interest due in '.$o['days'][0].' days', 'w2' => 'Interest due in '.($o['days'][0] + 1).'–'.$o['days'][1].' days', 'w3' => 'Interest due in '.($o['days'][1] + 1).'–'.$o['days'][2].' days'],
            'maturity' => ['w1' => 'Matures in '.$o['days'][0].' days', 'w2' => 'Matures in '.($o['days'][0] + 1).'–'.$o['days'][1].' days', 'w3' => 'Matures in '.($o['days'][1] + 1).'–'.$o['days'][2].' days'],
            'forfeit' => ['soon' => 'Forfeiture within '.$o['forfeit_days'].' days', 'notice' => 'Forfeiture notice due', 'ready' => 'Ready to forfeit'],
        ];
    }

    /** @var array<int, list<array>> */
    private static array $memo = [];

    /** @return array{0: string, 1: array} SQL (no leading AND) and its bindings, for one row key. */
    private static function condition(string $key, array $o): array
    {
        $today = today()->toDateString();
        $active = "dhiran_loans.status = 'active'";
        $nextDue = "(dhiran_loans.loan_date::date + dhiran_loans.interest_periods_paid * INTERVAL '1 month')::date";
        $installment = "$active AND dhiran_loans.advance_interest_months > 0";
        $point = "(dhiran_loans.maturity_date::date + COALESCE(dhiran_loans.grace_period_days, 0))"; // the last day before a notice may go out
        $plain = "$active AND dhiran_loans.forfeiture_notice_sent_at IS NULL AND dhiran_loans.maturity_date IS NOT NULL";

        return match ($key) {
            'pending'        => ["dhiran_loans.status = 'pending_evidence'", []],
            'inst_late'      => ["$installment AND $nextDue < ?::date", [$today]],
            'inst_w1'        => ["$installment AND $nextDue BETWEEN ?::date AND ?::date + ?::integer", [$today, $today, $o['days'][0]]],
            'inst_w2'        => ["$installment AND $nextDue BETWEEN ?::date + ?::integer AND ?::date + ?::integer", [$today, $o['days'][0] + 1, $today, $o['days'][1]]],
            'inst_w3'        => ["$installment AND $nextDue BETWEEN ?::date + ?::integer AND ?::date + ?::integer", [$today, $o['days'][1] + 1, $today, $o['days'][2]]],
            'mat_w1'         => ["$active AND dhiran_loans.maturity_date::date BETWEEN ?::date AND ?::date + ?::integer", [$today, $today, $o['days'][0]]],
            'mat_w2'         => ["$active AND dhiran_loans.maturity_date::date BETWEEN ?::date + ?::integer AND ?::date + ?::integer", [$today, $o['days'][0] + 1, $today, $o['days'][1]]],
            'mat_w3'         => ["$active AND dhiran_loans.maturity_date::date BETWEEN ?::date + ?::integer AND ?::date + ?::integer", [$today, $o['days'][1] + 1, $today, $o['days'][2]]],
            'forfeit_soon'   => ["$plain AND $point BETWEEN ?::date AND ?::date + ?::integer", [$today, $today, $o['forfeit_days']]],
            'forfeit_notice' => ["$plain AND $point < ?::date", [$today]],
            'forfeit_ready'  => ["$active AND dhiran_loans.forfeiture_notice_sent_at IS NOT NULL AND COALESCE(dhiran_loans.forfeiture_deadline, dhiran_loans.forfeiture_notice_sent_at::date) <= ?::date", [$today]],
        };
    }

    private const BAND_KEY = ['late' => 'inst_late', 'w1' => 'inst_w1', 'w2' => 'inst_w2', 'w3' => 'inst_w3'];
    private const MATURITY_KEY = ['w1' => 'mat_w1', 'w2' => 'mat_w2', 'w3' => 'mat_w3'];
    private const STAGE_KEY = ['soon' => 'forfeit_soon', 'notice' => 'forfeit_notice', 'ready' => 'forfeit_ready'];

    /** Loans-list filter ?installment=late|w1|w2|w3 (windows are the shop's own day settings). */
    public static function installmentWhere(Builder $q, string $band, int $shopId): Builder
    {
        [$sql, $bind] = self::condition(self::BAND_KEY[$band], self::options($shopId));

        return $q->whereRaw($sql, $bind);
    }

    /** Loans-list filter ?maturity=w1|w2|w3: active loans whose maturity date falls in the shop's own windows. */
    public static function maturityWhere(Builder $q, string $band, int $shopId): Builder
    {
        [$sql, $bind] = self::condition(self::MATURITY_KEY[$band], self::options($shopId));

        return $q->whereRaw($sql, $bind);
    }

    /** Loans-list filter ?forfeit=soon|notice|ready */
    public static function forfeitWhere(Builder $q, string $stage, int $shopId): Builder
    {
        [$sql, $bind] = self::condition(self::STAGE_KEY[$stage], self::options($shopId));

        return $q->whereRaw($sql, $bind);
    }

    /** @return list<array{key: string, tone: string, count: int, title: string, hint: string, url: string}> Only rows that are on and have something to do. */
    public static function forShop(int $shopId): array
    {
        return self::$memo[$shopId] ??= self::build($shopId);
    }

    /** Drop the per-request memo (tests and long-running workers). */
    public static function forget(): void
    {
        self::$memo = [];
    }

    private static function build(int $shopId): array
    {
        $o = self::options($shopId);
        $select = [];
        $bindings = [];
        foreach (array_diff(array_keys(self::ROWS), $o['off']) as $key) {
            [$sql, $bind] = self::condition($key, $o);
            $select[] = "COUNT(*) FILTER (WHERE $sql) AS {$key}_n, MIN(dhiran_loans.id) FILTER (WHERE $sql) AS {$key}_id";
            array_push($bindings, ...$bind, ...$bind);
        }
        $row = (array) DB::table('dhiran_loans')->where('dhiran_loans.shop_id', $shopId)
            ->selectRaw(implode(', ', $select), $bindings)->first();

        $rows = [];
        foreach (self::ROWS as $key => [$tone, $hint, $filter]) {
            $n = (int) ($row[$key.'_n'] ?? 0);
            if ($n === 0) {
                continue;
            }
            $rows[] = [
                'key' => $key, 'tone' => $tone, 'count' => $n,
                'title' => $n.' '.($n === 1 ? 'loan' : 'loans').' '.self::text($key, $o, $n),
                'hint' => $hint,
                'url' => $n === 1 ? route('dhiran.show', (int) $row[$key.'_id']) : route('dhiran.loans', $filter),
            ];
        }

        return $rows;
    }
}
