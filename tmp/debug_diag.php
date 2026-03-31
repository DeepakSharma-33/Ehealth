<?php
require_once __DIR__ . '/../config/config.php';
$db = db();
$pid = 'P202603001';

function existsTable(mysqli $db, string $t): bool {
  $s = $db->prepare('SHOW TABLES LIKE ?');
  $s->bind_param('s', $t);
  $s->execute();
  $ok = $s->get_result()->num_rows > 0;
  $s->close();
  return $ok;
}

$tables = ['diagnosis_records','telestudio_diagnosis','teleconsult_diagnosis'];
foreach($tables as $t){
  echo "TABLE {$t}: " . (existsTable($db,$t) ? "YES" : "NO") . PHP_EOL;
  if (!existsTable($db,$t)) continue;

  $c = $db->prepare("SELECT COUNT(*) c FROM {$t} WHERE patient_id = ?");
  $c->bind_param('s',$pid);
  $c->execute();
  $cnt = $c->get_result()->fetch_assoc()['c'] ?? 0;
  $c->close();
  echo "  patient rows: {$cnt}" . PHP_EOL;

  $q = $db->prepare("SELECT * FROM {$t} WHERE patient_id = ? ORDER BY id DESC LIMIT 3");
  $q->bind_param('s',$pid);
  $q->execute();
  $r = $q->get_result();
  while($row = $r->fetch_assoc()){
    $id = $row['id'] ?? '';
    $dt = $row['diagnosis_date'] ?? '(no diagnosis_date col/value)';
    $tsDoc = $row['telestudio_doctor_id'] ?? '';
    $ehDoc = $row['ehealth_doctor_id'] ?? '';
    echo "    id={$id} diagnosis_date={$dt} ehealth_doctor_id={$ehDoc} telestudio_doctor_id={$tsDoc}" . PHP_EOL;
  }
  $q->close();
}