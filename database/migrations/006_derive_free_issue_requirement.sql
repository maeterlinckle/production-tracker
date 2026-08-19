-- Make the free-issue requirement a derived figure rather than a running total.
--
-- It used to be a number that got added to: the order set it, a rejection
-- raised it, a request for replacement material raised it again. That worked
-- only for as long as nothing changed afterwards. Failing two more parts before
-- the first replacement arrived stacked a second request beside the first, and
-- the pair of them no longer described the shortfall — they described the
-- history of somebody pressing a button.
--
-- It is now worked out from the line every time anything moves:
--
--     enough for what is still on the order   (qty_ordered - qty_cancelled)
--   + enough to remake what has failed        (qty_failed)
--
-- with the two rounded up separately, because a single failed part still needs
-- a whole bar and the spare that leaves is the honest answer. Rejected material
-- needs no term: it counts as received and is subtracted again when the
-- outstanding figure is worked out, so rejecting three puts exactly three back
-- on to what is owed.
--
-- This statement brings existing rows on to that footing. Nothing in the schema
-- changes; what changes is that the column is now a cache of a calculation
-- rather than somewhere state accumulates, and OrderLine::recalculateTotals()
-- rewrites it after every move.

UPDATE order_lines ol
  JOIN parts p ON p.id = ol.part_id
   SET ol.qty_free_issue_required = CASE
       WHEN p.has_free_issue = 0 THEN 0
       WHEN p.free_issue_relationship = 'divide' THEN
           CEIL(GREATEST(ol.qty_ordered - ol.qty_cancelled, 0) / p.free_issue_factor)
           + CEIL(ol.qty_failed / p.free_issue_factor)
       WHEN p.free_issue_relationship = 'multiply' THEN
           (GREATEST(ol.qty_ordered - ol.qty_cancelled, 0) + ol.qty_failed) * p.free_issue_factor
       ELSE GREATEST(ol.qty_ordered - ol.qty_cancelled, 0) + ol.qty_failed
   END;
