<?php
require "config.php";
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $sch_id = $_POST['sch_id'];
    $daily = $_POST['daily'];
    $mid = $_POST['mid'];
    $final = $_POST['final'];
    $total = $_POST['total']; 
    $resit = $_POST['resit'];

    foreach ($daily as $stu_uuid => $d_val) {
        $m_val = $mid[$stu_uuid] ?? 0;
        $f_val = $final[$stu_uuid] ?? 0;
        $t_val = $total[$stu_uuid] ?? 0;
        $r_val = $resit[$stu_uuid] ?? 0;

        $sql = "UPDATE scores SET daily_score=?, mid_score=?, final_score=?, total_score=?, resit_score=? WHERE student_uuid=? AND schedule_id=?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("dddddii", $d_val, $m_val, $f_val, $t_val, $r_val, $stu_uuid, $sch_id);
        $stmt->execute();
    }
    header("Location: dashboard.php?sch_id=" . $sch_id);
}