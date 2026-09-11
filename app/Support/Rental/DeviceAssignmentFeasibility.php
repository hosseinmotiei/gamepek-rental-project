<?php

namespace App\Support\Rental;

/**
 * Can every reservation still be given its own physical device?
 *
 * PURE. Given date intervals (inclusive ISO dates), some already fixed to a
 * device and some not yet assigned, and the devices an unassigned
 * reservation may use, answers whether an assignment exists in which no
 * device carries two overlapping intervals.
 *
 * WHY THIS AND NOT A COUNT. Reservations are paid before a device is
 * attached, and attachment is a human choice. "Reservations <= devices" can
 * admit a set that a later manual choice strands (two devices; 1-5, 6-10 and
 * 3-8: attaching 1-5 and 6-10 to different devices leaves 3-8 nothing). So
 * admission and every manual attachment ask this instead: it never picks a
 * device, it only refuses a state in which some reservation could not be
 * served.
 *
 * Backtracking with a node budget; sizes are one product's overlapping
 * bookings. Exceeding the budget answers false -- fail closed.
 */
final class DeviceAssignmentFeasibility
{
    public const NODE_LIMIT = 20_000;

    /**
     * @param  list<array{id: int|string, start: string, end: string, device: ?int}>  $intervals
     * @param  list<int>  $eligibleDevices  devices an unassigned interval may take
     */
    public static function isFeasible(array $intervals, array $eligibleDevices): bool
    {
        $busy = [];
        $free = [];

        foreach ($intervals as $interval) {
            if ($interval['device'] === null) {
                $free[] = $interval;
            } else {
                $busy[$interval['device']][] = [$interval['start'], $interval['end']];
            }
        }

        usort($free, fn ($a, $b) => [$a['start'], $a['end']] <=> [$b['start'], $b['end']]);

        $nodes = 0;

        return self::assign($free, 0, $busy, array_values(array_unique($eligibleDevices)), $nodes);
    }

    /**
     * Split intervals into groups that overlap transitively. Groups never
     * compete for a device on the same day, so each can be judged alone.
     *
     * @param  list<array{id: int|string, start: string, end: string, device: ?int}>  $intervals
     * @return list<list<array{id: int|string, start: string, end: string, device: ?int}>>
     */
    public static function components(array $intervals): array
    {
        usort($intervals, fn ($a, $b) => [$a['start'], $a['end']] <=> [$b['start'], $b['end']]);

        $groups = [];
        $current = [];
        $currentEnd = null;

        foreach ($intervals as $interval) {
            if ($current !== [] && $interval['start'] > $currentEnd) {
                $groups[] = $current;
                $current = [];
                $currentEnd = null;
            }

            $current[] = $interval;
            $currentEnd = $currentEnd === null || $interval['end'] > $currentEnd ? $interval['end'] : $currentEnd;
        }

        if ($current !== []) {
            $groups[] = $current;
        }

        return $groups;
    }

    /** The group that contains the interval with this id, or []. */
    public static function componentOf(array $intervals, int|string $id): array
    {
        foreach (self::components($intervals) as $group) {
            foreach ($group as $interval) {
                if ($interval['id'] === $id) {
                    return $group;
                }
            }
        }

        return [];
    }

    private static function assign(array $free, int $index, array &$busy, array $devices, int &$nodes): bool
    {
        if ($index === count($free)) {
            return true;
        }

        if (++$nodes > self::NODE_LIMIT) {
            return false;
        }

        $interval = $free[$index];
        $triedAnIdleDevice = false;

        foreach ($devices as $device) {
            $ranges = $busy[$device] ?? [];

            // Idle devices are interchangeable: trying one is trying them all.
            if ($ranges === []) {
                if ($triedAnIdleDevice) {
                    continue;
                }

                $triedAnIdleDevice = true;
            }

            if (self::clashes($ranges, $interval)) {
                continue;
            }

            $busy[$device][] = [$interval['start'], $interval['end']];

            if (self::assign($free, $index + 1, $busy, $devices, $nodes)) {
                return true;
            }

            array_pop($busy[$device]);
        }

        return false;
    }

    private static function clashes(array $ranges, array $interval): bool
    {
        foreach ($ranges as [$start, $end]) {
            if ($start <= $interval['end'] && $end >= $interval['start']) {
                return true;
            }
        }

        return false;
    }
}
