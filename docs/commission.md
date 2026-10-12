# Commission rules (P11-T1)

What the platform keeps from a fare, how the rule is chosen, how it is changed, and how it reaches a trip.

## `commission_rules`

One row per **zone, vehicle class (or every class) and period**: `type` (`percentage` | `fixed` | `percentage_plus_fixed`), `rate_bps`
(basis points, 2000 = 20 %), `fixed_amount`, `min_commission`, `max_commission` (null = no limit), `applies_to_surge`, `currency`
(the zone's), `effective_from`, `effective_to` (null = current), `created_by`. Money is integer minor units. A `vehicle_class` of null
means every class of the zone; a rule for a class beats the zone-wide rule (`CommissionRuleLookup::at()`).

### History is protected by the database (same as [fares.md](fares.md))

- an exclusion constraint refuses overlapping periods for one zone and class (the zone-wide rule counts as its own "class");
- a trigger refuses every `UPDATE` except closing an open row once, and refuses `DELETE`;
- CHECK constraints refuse a rate over 100 %, negative money, `max < min`, an unknown type or class, a period that ends before it
  starts, and terms a type does not use (a fixed rule has no rate, a percentage rule no fixed amount).

"Which rule applied at time T":

```sql
SELECT * FROM commission_rules
WHERE zone_id = :zone AND vehicle_class IS NOT DISTINCT FROM :class
  AND effective_from <= :t AND (effective_to IS NULL OR effective_to > :t);
```

`trips.commission_rule_id` is a foreign key to this table.

## `CommissionCalculator`

`calculate(FareBreakdown, CommissionRule): CommissionResult` splits a **final** fare:

```
taxFree    = total - tax                 (tax is never part of the commission base, and never the driver's)
base       = taxFree - surge             (only when applies_to_surge = false)
raw        = base x rate (+ fixed)       (by type; the percentage rounds half-up, integers only)
commission = raw lifted to min, held to max, and never above taxFree
driver     = taxFree - commission        (so the driver keeps all of a surge the rule leaves out)
```

Tips, if added later, are not part of the fare and go to the driver in full. A rule in another currency than the fare is refused.
`tests/fixtures/commission-vectors.json` holds cases that both PHPUnit (`CommissionCalculatorTest`) and Vitest (`commission.test.ts`,
the admin preview) check, so the preview and the stored commission cannot drift.

## On the trip

`TripCommission::snapshot(zone, vehicleClass, fareBreakdown, at)` finds the rule in force **at completion** and returns
`commission_rule_id`, `commission_amount` and `driver_earnings_amount`. The trip lifecycle (P7) writes them in the save that completes the
trip: `$trip->transitionTo(TripStatus::Completed, attributes: [...$snapshot, 'fare_amount' => ..., 'fare_breakdown' => ...])`.
From then on the trip's pricing columns are frozen (`Trip::PRICING_COLUMNS`), and the rule it points at can never change or be deleted,
so **a rule change never alters a completed trip's commission**. A zone with no rule takes no commission (all zeros, no rule id).

## Admin (permission `fares.manage`)

| Route | What |
| --- | --- |
| `GET /admin/commission-rules` | the rules in force (paginated; `filter[search]` zone name/code, `filter[vehicle_class]` a class or `all`, `filter[zone]`, `sort=zone|effective_from`), the preview (`?preview=<rule id>`) and a rule's history (`?history=<rule id>`) |
| `POST /admin/commission-rules` | add a version: `zone_id`, `vehicle_class` (empty = every class), `type`, `rate_bps`, `fixed_amount`, `min_commission`, `max_commission`, `applies_to_surge` |

Saving closes the open rule of that zone and class (`effective_to = now`) and inserts the next from now, in one transaction under the zone's
row lock; saving the terms already in force changes nothing; a type stores zeros for the terms it does not use; the currency is the zone's.
Audit: `commission_rule.created`, `commission_rule.versioned`. The page's form takes the rate as a percentage ("17.5") and money in major
units; it sends basis points and minor units.
