const NANOS_PER_USD = 1_000_000_000;

/**
 * The one place that decides how an amount of money is written.
 *
 * Every figure the server sends is an integer number of nanodollars, because
 * the provider bills in fractions of a cent and reports costs to nine decimal
 * places. That creates a display problem a currency formatter does not solve on
 * its own: at two decimal places a generation costing $0.0140 is written $0.01,
 * and three of them read as $0.03 while the balance beside them has moved
 * $0.042. The page visibly fails to add up.
 *
 * So the rule is explicit. Below a dollar, where these amounts almost always
 * sit, four decimal places. At a dollar or more, where the extra digits only
 * add noise, two — a $5.00 balance written $5.0000 reads like a bug.
 *
 * Four is a judgement, not a guarantee of exactness: a nanodollar amount can
 * carry nine decimals, and nothing here pretends otherwise. It is the precision
 * at which the costs this application actually produces stop disagreeing with
 * each other on screen. An exact figure, when one is needed, comes from the
 * server as a string rather than from rendering an integer here.
 *
 * The only arithmetic is the conversion to dollars for display. Prices,
 * markups and totals are all computed server-side by App\Domain\Pricing, so no
 * money is ever calculated in the browser.
 */

export function formatNanos(nanos) {
    if (nanos === null || nanos === undefined) {
        return '—';
    }

    const dollars = nanos / NANOS_PER_USD;
    const magnitude = Math.abs(dollars);
    const decimals = magnitude < 1 ? 4 : 2;

    const formatted = magnitude.toLocaleString('en-US', {
        style: 'currency',
        currency: 'USD',
        minimumFractionDigits: decimals,
        maximumFractionDigits: decimals,
    });

    return dollars < 0 ? `-${formatted}` : formatted;
}

export function useMoney() {
    return { formatNanos };
}
