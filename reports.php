<?php
error_reporting(E_ALL);
ini_set('display_errors',1);

if (session_status() === PHP_SESSION_NONE) { session_start(); }

require_once "config.php";
require_once 'access_control.php';
check_access(['admin','accounting','staff']);

/* FILTER */
$month = $_GET['month'] ?? 3;
$year  = $_GET['year'] ?? date('Y');
$type = $_GET['type'] ?? 'today';

/* SUMMARY */

// WORK ORDERS
if($type == 'today'){
    $stmt=$conn->prepare("
    SELECT COUNT(*) 
    FROM work_orders
    WHERE DATE(created_at)=CURDATE()
    ");
    $stmt->execute();
}else{
    $stmt=$conn->prepare("
    SELECT COUNT(*) 
    FROM work_orders
    WHERE MONTH(created_at)=? AND YEAR(created_at)=?
    ");
    $stmt->execute([$month,$year]);
}
$total_orders=$stmt->fetchColumn();

// REVENUE
if($type == 'today'){
    $stmt=$conn->prepare("
    SELECT COALESCE(SUM(amount),0)
    FROM accounting_transactions
    WHERE type='Income'
    AND DATE(txn_date)=CURDATE()
    ");
    $stmt->execute();
}else{
    $stmt=$conn->prepare("
    SELECT COALESCE(SUM(amount),0)
    FROM accounting_transactions
    WHERE type='Income'
    AND MONTH(txn_date)=?
    AND YEAR(txn_date)=?
    ");
    $stmt->execute([$month,$year]);
}
$total_revenue=$stmt->fetchColumn();

// EXPENSE
if($type == 'today'){
    $stmt=$conn->prepare("
    SELECT COALESCE(SUM(amount),0)
    FROM accounting_transactions
    WHERE type='Expense'
    AND DATE(txn_date)=CURDATE()
    ");
    $stmt->execute();
}else{
    $stmt=$conn->prepare("
    SELECT COALESCE(SUM(amount),0)
    FROM accounting_transactions
    WHERE type='Expense'
    AND MONTH(txn_date)=?
    AND YEAR(txn_date)=?
    ");
    $stmt->execute([$month,$year]);
}
$total_expense=$stmt->fetchColumn();

// DELIVERED
if($type == 'today'){
    $stmt=$conn->prepare("
    SELECT COUNT(*)
    FROM deliveries
    WHERE status='delivered'
    AND DATE(delivered_date)=CURDATE()
    ");
    $stmt->execute();
}else{
    $stmt=$conn->prepare("
    SELECT COUNT(*)
    FROM deliveries
    WHERE status='delivered'
    AND MONTH(delivered_date)=?
    AND YEAR(delivered_date)=?
    ");
    $stmt->execute([$month,$year]);
}
$total_delivered=$stmt->fetchColumn();

/* PRODUCTION */
if($type == 'today'){
    $production=$conn->prepare("
    SELECT status, COUNT(*) total
    FROM work_orders
    WHERE DATE(created_at)=CURDATE()
    GROUP BY status
    ");
    $production->execute();
}else{
    $production=$conn->prepare("
    SELECT status, COUNT(*) total
    FROM work_orders
    WHERE MONTH(created_at)=? AND YEAR(created_at)=?
    GROUP BY status
    ");
    $production->execute([$month,$year]);
}
$prod_data=$production->fetchAll(PDO::FETCH_ASSOC);

/* INVENTORY */
if($type == 'today'){
    $inventory=$conn->prepare("
    SELECT ii.item_name, SUM(sm.quantity) total_used
    FROM stock_movements sm
    JOIN inventory_items ii ON ii.id=sm.item_id
    WHERE sm.movement_type != 'Stock In'
    AND DATE(sm.created_at)=CURDATE()
    GROUP BY ii.item_name
    ORDER BY total_used DESC
    LIMIT 5
    ");
    $inventory->execute();
}else{
    $inventory=$conn->prepare("
    SELECT ii.item_name, SUM(sm.quantity) total_used
    FROM stock_movements sm
    JOIN inventory_items ii ON ii.id=sm.item_id
    WHERE sm.movement_type != 'Stock In'
    AND MONTH(sm.created_at)=?
    AND YEAR(sm.created_at)=?
    GROUP BY ii.item_name
    ORDER BY total_used DESC
    LIMIT 5
    ");
    $inventory->execute([$month,$year]);
}
$inv_data=$inventory->fetchAll(PDO::FETCH_ASSOC);

/* LOGISTICS */
if($type == 'today'){
    $stmt=$conn->prepare("
    SELECT COUNT(*) FROM packing_jobs
    WHERE DATE(date_packed)=CURDATE()
    ");
    $stmt->execute();
}else{
    $stmt=$conn->prepare("
    SELECT COUNT(*) FROM packing_jobs
    WHERE MONTH(date_packed)=? AND YEAR(date_packed)=?
    ");
    $stmt->execute([$month,$year]);
}
$total_packed=$stmt->fetchColumn();

/* HR */
if($type == 'today'){
    $stmt=$conn->prepare("
    SELECT COALESCE(SUM(net_pay),0)
    FROM payroll
    WHERE DATE(period_end)=CURDATE()
    ");
    $stmt->execute();
}else{
    $stmt=$conn->prepare("
    SELECT COALESCE(SUM(net_pay),0)
    FROM payroll
    WHERE MONTH(period_end)=? AND YEAR(period_end)=?
    ");
    $stmt->execute([$month,$year]);
}
$total_payroll=$stmt->fetchColumn();
?>

<!DOCTYPE html>
<html>
<head>
<title>Reports</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

<style>
body{
background:#f4f6f9;
font-family:Arial;
/* REMOVE overflow:hidden */
}

.main-content{
margin-left:260px;
padding:20px;
margin-top:70px;
/* REMOVE height + overflow */
}

.card{
border-radius:10px;
height:100%;
}

.card-body{
padding:15px;
}

h6{
font-weight:600;
margin-bottom:10px;
}

.summary-card{
text-align:center;
padding:10px;
}

.summary-card h3{
margin:5px 0;
font-size:22px;
}

.summary-card h3{
    margin:5px 0;
    font-size:22px;
    font-weight:bold;
}

/* COLORS */
.text-yellow{ color:#f1c40f; }
.text-green{ color:#27ae60; }
.text-red{ color:#e74c3c; }
.text-blue{ color:#3498db; }
</style>

</head>

<body>

<?php include 'sidebar.php'; ?>
<?php include 'header.php'; ?>

<div class="main-content">

<!-- TITLE -->
<div class="card mb-2">
<div class="card-body">
<h5>
<?= ($type=='today') 
    ? "Today's Report (".date("F d, Y").")" 
    : "Monthly Report" ?>
</h5>
</div>
</div>

<!-- FILTER -->

<form method="GET" class="row mb-2">

<div class="col-md-3">
<select name="type" class="form-control">
<option value="today" <?=($type=='today')?'selected':''?>>Today</option>
<option value="monthly" <?=($type=='monthly')?'selected':''?>>Monthly</option>
</select>
</div>

<div class="col-md-3">
<select name="month" class="form-control">
<?php for($m=1;$m<=12;$m++){ ?>
<option value="<?=$m?>" <?=($month==$m)?'selected':''?>>
<?=date("F",mktime(0,0,0,$m,1))?>
</option>
<?php } ?>
</select>
</div>

<div class="col-md-3">
<input type="number" name="year" value="<?=$year?>" class="form-control">
</div>

<div class="col-md-2">
<button class="btn btn-primary w-100">Filter</button>
</div>

</form>

<!-- SUMMARY -->
<div class="row g-2 mb-2">

<div class="col-md-3">
<div class="card summary-card">
<h6>Work Orders</h6>
<h3 class="text-yellow"><?=$total_orders?></h3>
</div>
</div>

<div class="col-md-3">
<div class="card summary-card">
<h6>Revenue</h6>
<h3 class="text-green">₱<?=number_format($total_revenue,2)?></h3>
</div>
</div>

<div class="col-md-3">
<div class="card summary-card">
<h6>Expenses</h6>
<h3 class="text-red">₱<?=number_format($total_expense,2)?></h3>
</div>
</div>

<div class="col-md-3">
<div class="card summary-card">
<h6>Delivered</h6>
<h3 class="text-blue"><?=$total_delivered?></h3>
</div>
</div>

</div>

<!-- GRID REPORTS -->
<div class="row g-2">

<!-- Production -->
<div class="col-md-6">
<div class="card">
<div class="card-body">

<h6>Production</h6>
<hr>

<?php foreach($prod_data as $p){ ?>
<p class="mb-1"><?=$p['status']?>: <b><?=$p['total']?></b></p>
<?php } ?>


</div>
</div>
</div>

<!-- Inventory -->
<div class="col-md-6">
<div class="card">
<div class="card-body">

<h6>Inventory Usage</h6>
<hr>

<?php if(!empty($inv_data)){ ?>

<?php foreach($inv_data as $i){ ?>
    <p class="mb-1"><?=$i['item_name']?> - <b><?=$i['total_used']?></b></p>
<?php } ?>

<?php } else { ?>

<p class="text-muted">No inventory usage</p>

<?php } ?>

</div>
</div>
</div>

<!-- Logistics -->
<div class="col-md-6">
<div class="card">
<div class="card-body">

<h6>Logistics</h6>
<hr>

<p class="mb-1">Packed: <b><?=$total_packed?></b></p>
<p class="mb-1">Delivered: <b><?=$total_delivered?></b></p>

</div>
</div>
</div>

<!-- HR -->
<div class="col-md-6">
<div class="card">
<div class="card-body">
<h6>HR</h6>

<p class="text-muted">No attendance data</p>



<hr>


<p>Total Payroll: <b>₱<?=number_format($total_payroll,2)?></b></p>

</div>
</div>
</div>

</div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<?php include 'footer.php'; ?>

</body>
</html>