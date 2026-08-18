-- Bring qty_completed into line with the stage on lines that were marked
-- complete by hand.
--
-- Until now, moving a line to "complete" from the production status dropdown
-- set the stage and nothing else, so a line could read as finished while
-- qty_completed sat at 0. Two things went wrong downstream: the parts-on-order
-- report counted the whole quantity as still to make, and the goods-out
-- delivery note screen would not offer parts that had in fact been made.
--
-- OrderLine::setStage() now keeps the two in step. This repairs the rows that
-- predate that.
--
-- Only ever raised, never lowered — a line that legitimately recorded partial
-- completion before being closed keeps its own figure if it is already higher.

UPDATE order_lines
   SET qty_completed = qty_ordered
 WHERE stage IN ('complete', 'closed')
   AND qty_completed < qty_ordered;

-- A line cannot have delivered more than it made. Where that happened it is the
-- same missing-counter bug seen from the other side: the delivery was recorded
-- against a line whose completion never was.

UPDATE order_lines
   SET qty_completed = qty_delivered
 WHERE qty_completed < qty_delivered;
