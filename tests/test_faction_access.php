<?php
require __DIR__.'/../app/services/FactionAccess.php';
$g=GuristasFactionAccess::classify(500004,true); assert($g['posture']==='gallente');
assert(!GuristasFactionAccess::permits($g,'pirate'));
assert(GuristasFactionAccess::classify(null,false)['posture']==='unknown');
assert(GuristasFactionAccess::classify(500001,true)['posture']==='caldari');
echo "Faction access unit checks passed.\n";
