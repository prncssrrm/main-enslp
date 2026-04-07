<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

require_once "config.php";
require_once 'access_control.php';
check_access(['admin','production','engineer','accounting']);

function h($s){
    return htmlspecialchars($s ?? '',ENT_QUOTES,'UTF-8');
}

$self = "projects.php";

$msg="";

/* FLASH */
if(isset($_GET['msg'])){
    if($_GET['msg']=="wo_created") $msg="Work Order Created!";
}

/* ADD WORK ORDER */
if($_SERVER['REQUEST_METHOD']=="POST" && isset($_POST['add_wo'])){

    if($_SESSION['role'] == 'production'){
        die("Unauthorized access");
    }

    $product_name = trim($_POST['product_name']);
    $client = trim($_POST['wo_client_name']);
    $qty = (int)$_POST['quantity'];
    $selling_price = (float)$_POST['selling_price'];
    $unit_cost = (float)$_POST['cost'];
    $status = "Pending";
    $date_started = $_POST['date_started'];
    $date_completed = $_POST['date_completed'];

    

    /* GENERATE WO NUMBER */
    $year = date("Y");
    $last = $conn->query("SELECT id FROM work_orders ORDER BY id DESC LIMIT 1")->fetch();
    $next = (int)($last['id'] ?? 0) + 1;

    $wo_no = "WO-$year-".str_pad($next,4,"0",STR_PAD_LEFT);

    /* INSERT WORK ORDER */
    $stmt = $conn->prepare("
    INSERT INTO work_orders
    (wo_no,product_name,client,qty,status,date_started,date_completed,created_at,selling_price)
    VALUES(?,?,?,?,?,?,?,NOW(),?)
    ");

    $stmt->execute([
        $wo_no,
        $product_name,
        $client,
        $qty,
        $status,
        $date_started,
        $date_completed,
        $selling_price
    ]);

    $wo_id = $conn->lastInsertId();

    /* AUTO ACCOUNTING (if completed) */
   
    header("Location:$self?msg=wo_created");
    exit();
}

/* FETCH */
$work_orders = $conn->query("
SELECT 
w.*,

(SELECT COUNT(*) FROM cutting_jobs c WHERE c.work_order_id = w.id) as cutting,
(SELECT COUNT(*) FROM etching_jobs e WHERE e.work_order_id = w.id) as etching,
(SELECT COUNT(*) FROM lamination_jobs l WHERE l.work_order_id = w.id) as lamination,
(SELECT COUNT(*) FROM inspection_qc q WHERE q.work_order_id = w.id) as qc,
(SELECT COUNT(*) FROM packing_jobs p WHERE p.work_order_id = w.id) as packing,
(SELECT COALESCE(SUM(d.delivery_qty),0) 
 FROM deliveries d 
 WHERE d.wo_id = w.id AND d.status='delivered') as delivered_qty

FROM work_orders w
ORDER BY w.id DESC
")->fetchAll(PDO::FETCH_ASSOC);

?>

<!DOCTYPE html>
<html>
<head>
<title>Work Orders</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

<style>
body{
    background:#f4f6f9;
    font-family:Arial;
}
.main-content{
    margin-left:260px;
    padding:25px;
    margin-top:70px;
}
.card{
    border:none;
    border-radius:10px;
    box-shadow:0 3px 10px rgba(0,0,0,0.05);
}
</style>

</head>

<body>

<?php include "sidebar.php"; ?>
<?php include "header.php"; ?>

<div class="main-content">

<div class="card mb-3">
<div class="card-body">
<h3>Work Orders</h3>
</div>
</div>

<?php if($msg): ?>
<div class="alert alert-success"><?=h($msg)?></div>
<?php endif; ?>

<div class="card">

<div class="card-header">

<?php if($_SESSION['role'] != 'production'): ?>
<button class="btn btn-primary"
data-bs-toggle="modal"
data-bs-target="#addWorkOrderModal">
+ Create Work Order
</button>
<?php endif; ?>

</div>

<div class="card-body table-responsive">

<table class="table table-bordered">

<thead>
<tr>
<th>ID</th>
<th>WO No</th>
<th>Product</th>
<th>Client</th>
<th>Qty</th>
<th>Price</th>
<th>Status</th>
<th>Date Started</th>
<th>Date Completed</th>
</tr>
</thead>

<tbody>

<?php if($work_orders): ?>
<?php foreach($work_orders as $wo): ?>

<tr>
<td><?=h($wo['id'])?></td>
<td><strong><?=h($wo['wo_no'])?></strong></td>
<td><?=h($wo['product_name'])?></td>
<td><?=h($wo['client'])?></td>
<td><?=h($wo['qty'])?></td>
<td>₱<?=number_format($wo['selling_price'],2)?></td>

<td>
<?php

if($wo['delivered_qty'] >= $wo['qty']){
    $status = "Completed";
}
else if($wo['packing'] > 0){
    $status = "Packed";
}
else if(
    $wo['cutting'] > 0 ||
    $wo['etching'] > 0 ||
    $wo['lamination'] > 0 ||
    $wo['qc'] > 0
){
    $status = "In Production";
}
else{
    $status = "Pending";
}

/* COLOR */
$badge = "secondary";
if($status=="Pending") $badge="secondary";
if($status=="In Production") $badge="primary";
if($status=="Packed") $badge="warning";
if($status=="Completed") $badge="success";

?>

<span class="badge bg-<?=$badge?>"><?=$status?></span>
</td>

<td><?=h($wo['date_started'])?></td>
<td><?=h($wo['date_completed'] ?? "-")?></td>

</tr>

<?php endforeach; ?>
<?php else: ?>

<tr>
<td colspan="9" class="text-center">No work orders</td>
</tr>

<?php endif; ?>

</tbody>

</table>

</div>

</div>

</div>

<!-- 🔥 MODAL (IMPORTANT) -->
<?php if($_SESSION['role'] != 'production'): ?>
<!-- 🔥 MODAL -->
<div class="modal fade" id="addWorkOrderModal">
<div class="modal-dialog">
<form method="POST" class="modal-content">

<div class="modal-header">
<h5>Create Work Order</h5>
<button type="button" class="btn-close" data-bs-dismiss="modal"></button>
</div>

<div class="modal-body">

<div class="mb-2">
<label>Product</label>
<select name="product_name" id="product" class="form-control" required>
<option value="">Select Product</option>

<?php
$stmt = $conn->query("
SELECT item_name, selling_price, cost 
FROM inventory_items 
WHERE category='Finished Good' AND status='active'
");

foreach($stmt as $row){
?>
<option 
    value="<?=$row['item_name']?>"
    data-price="<?=$row['selling_price']?>"
    data-cost="<?=$row['cost']?>"
>
    <?=$row['item_name']?>
</option>
<?php } ?>
</select>


</div>

<div class="mb-2">
<label>Client</label>
<select name="wo_client_name" class="form-control" required>
<option value="">Select Client</option>

<option>Samsung Electronics Philippines</option>
<option>Brother Industries Philippines</option>
<option>Mitsubishi Electric Asia</option>
<option>Epson Precision Philippines</option>
<option>Panasonic Manufacturing Philippines</option>
<option>Canon Business Services Philippines</option>
<option>Texas Instruments Philippines</option>
<option>ON Semiconductor Philippines</option>
<option>Analog Devices Philippines</option>
<option>Amkor Technology Philippines</option>

</select>
</div>

<div class="mb-2">
<label>Quantity</label>
<input type="number" name="quantity" class="form-control" required>
</div>

<div class="mb-2">
<label>Selling Price</label>
<input type="number" step="0.01" name="selling_price" id="selling_price" class="form-control" required>
</div>

<div class="mb-2">
<label>Cost per unit</label>
<input type="number" step="0.01" name="cost" id="cost_per_unit" class="form-control" required>
</div>



<div class="mb-2">
<label>Date Started</label>
<input type="date" name="date_started" class="form-control" required>
</div>

<div class="mb-2">
<label>Date Completed</label>
<input type="date" name="date_completed" class="form-control" id="date_completed" disabled>
</div>

</div>

<div class="modal-footer">
<button class="btn btn-primary" name="add_wo">Save Work Order</button>
</div>

</form>
</div>
</div>
<?php endif; ?>



<script>

let product = document.getElementById('product');

if(product){
    product.addEventListener('change', function(){

        let selected = this.options[this.selectedIndex];

        let price = selected.getAttribute('data-price') || 0;
        let cost  = selected.getAttribute('data-cost') || 0;

        document.getElementById('selling_price').value = price;
        document.getElementById('cost_per_unit').value = cost;

    });
}

</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<?php include "footer.php"; ?>

</body>
</html>