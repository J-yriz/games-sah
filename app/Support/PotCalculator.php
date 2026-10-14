<?php

namespace App\Support;

/**
 * Pure pot calculator for No-Limit Texas Hold'em with physical cards.
 *
 * Input is plain arrays so it can be unit-tested without the database.
 *
 * Rules:
 * - Layers are built from actual contributions, never from frontend numbers.
 * - A layer with a single contributor is uncalled excess and is returned,
 *   not awarded as a pot.
 * - A layer with multiple contributors but a single eligible winner (others
 *   folded) is a valid pot awarded to the remaining eligible player.
 * - A layer with zero eligible winners (all contributors folded) is merged
 *   into the previous pot so chips are never ownerless.
 */
class PotCalculator
{
    /**
     * @param  array<int, array{user_id: int, seat: int, total: int, folded: bool}>  $contributions
     * @return array{pots: array<int, array{amount: int, eligible: array<int, int>}>, uncalled: array{user_id: int, amount: int}|null}
     */
    public static function calculate(array $contributions, int $dealerSeat = 0): array
    {
        $positive = [];
        foreach ($contributions as $c) {
            if ($c['total'] > 0) {
                $positive[] = $c;
            }
        }

        if ($positive === []) {
            return ['pots' => [], 'uncalled' => null];
        }

        // Unique sorted contribution levels.
        $levels = [];
        foreach ($positive as $c) {
            $levels[] = $c['total'];
        }
        $levels = array_values(array_unique($levels));
        sort($levels);

        /** @var array<int, array{amount: int, eligible: array<int, int>}> $pots */
        $pots = [];
        $uncalled = null;
        $prev = 0;
        $maxLevel = end($levels);

        foreach ($levels as $level) {
            $delta = $level - $prev;
            $prev = $level;

            if ($delta <= 0) {
                continue;
            }

            $contributors = [];
            foreach ($positive as $c) {
                if ($c['total'] >= $level) {
                    $contributors[] = $c;
                }
            }

            $eligible = [];
            foreach ($contributors as $c) {
                if (! $c['folded']) {
                    $eligible[] = $c;
                }
            }

            $amount = $delta * count($contributors);

            if ($amount <= 0) {
                continue;
            }

            // Single contributor at the top level: uncalled excess.
            if (count($contributors) === 1 && $level === $maxLevel) {
                $uncalled = [
                    'user_id' => $contributors[0]['user_id'],
                    'amount' => $amount,
                ];

                continue;
            }

            $eligibleIds = [];
            foreach ($eligible as $c) {
                $eligibleIds[] = $c['user_id'];
            }

            if ($eligibleIds === []) {
                // All contributors folded at this layer: merge into previous pot
                // so chips always have a winner (fold-win awards them to the
                // last remaining player).
                if ($pots !== []) {
                    $last = count($pots) - 1;
                    $pots[$last] = [
                        'amount' => $pots[$last]['amount'] + $amount,
                        'eligible' => $pots[$last]['eligible'],
                    ];
                } else {
                    $pots[] = ['amount' => $amount, 'eligible' => []];
                }

                continue;
            }

            // Deterministic eligible order: seat order starting left of dealer.
            $eligibleIds = self::orderBySeatFromDealer($eligibleIds, $positive, $dealerSeat);

            $pots[] = ['amount' => $amount, 'eligible' => $eligibleIds];
        }

        return ['pots' => $pots, 'uncalled' => $uncalled];
    }

    /**
     * Split a pot evenly (integer chips). Remainder (odd chips) go one chip
     * at a time starting left of the dealer, deterministic.
     *
     * @param  array<int, int>  $winnerIds
     * @param  array<int, array{user_id: int, seat: int}>  $contributions
     * @return array<int, int> map user_id => share
     */
    public static function splitPot(int $amount, array $winnerIds, array $contributions, int $dealerSeat = 0): array
    {
        $winnerIds = array_values(array_unique($winnerIds));

        if ($winnerIds === [] || $amount <= 0) {
            return [];
        }

        $count = count($winnerIds);
        $base = intdiv($amount, $count);
        $remainder = $amount % $count;

        $ordered = self::orderBySeatFromDealer($winnerIds, $contributions, $dealerSeat);

        $shares = [];
        foreach ($winnerIds as $id) {
            $shares[$id] = $base;
        }

        for ($i = 0; $i < $remainder; $i++) {
            $shares[$ordered[$i % $count]]++;
        }

        return $shares;
    }

    /**
     * @param  array<int, int>  $userIds
     * @param  array<int, array{user_id: int, seat: int}>  $contributions
     * @return array<int, int>
     */
    private static function orderBySeatFromDealer(array $userIds, array $contributions, int $dealerSeat): array
    {
        $seatByUser = [];
        foreach ($contributions as $c) {
            $seatByUser[$c['user_id']] = $c['seat'];
        }

        usort($userIds, function (int $a, int $b) use ($seatByUser, $dealerSeat): int {
            $sa = $seatByUser[$a] ?? 0;
            $sb = $seatByUser[$b] ?? 0;
            $da = ($sa - $dealerSeat + 100000) % 100000;
            $db = ($sb - $dealerSeat + 100000) % 100000;

            // Seat 0 offset means dealer themselves sort last.
            if ($da === 0) {
                $da = 100000;
            }
            if ($db === 0) {
                $db = 100000;
            }

            return $da <=> $db;
        });

        return $userIds;
    }
}
