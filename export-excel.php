<?php
require 'vendor/autoload.php';
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

$conn = new mysqli("localhost", "root", "", "pos");

// Handle filters
$filters = [];
if (!empty($_GET['customer_name'])) {
    $filters[] = "o.customer_name LIKE '%" . $conn->real_escape_string($_GET['customer_name']) . "%'";
}
if (!empty($_GET['product_name'])) {
    $filters[] = "p.product_name LIKE '%" . $conn->real_escape_string($_GET['product_name']) . "%'";
}
if (!empty($_GET['user_id'])) {
    $filters[] = "o.user_id = " . (int)$_GET['user_id'];
}
if (!empty($_GET['payment_method'])) {
    $filters[] = "o.payment_method = '" . $conn->real_escape_string($_GET['payment_method']) . "'";
}
if (isset($_GET['no_discount']) && $_GET['no_discount'] == '1') {
    $filters[] = "o.discounts = 0";
}
$where = $filters ? "WHERE " . implode(" AND ", $filters) : "";

// Query orders with product data
$sql = "SELECT o.*, p.product_name, p.price, p.tax
        FROM orders o
        JOIN products p ON o.product_id = p.id
        $where
        ORDER BY o.created_at DESC";
$result = $conn->query($sql);

// Spreadsheet setup
$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Orders');

// Set header row
$headers = [
    'Order ID', 'Product ID', 'Customer Name', 'Phone', 'Quantity',
    'Payment Method', 'User ID', 'Discount', 'Product Name',
    'Price', 'Tax', 'Total Price', 'Total Tax', 'Created At'
];
$sheet->fromArray($headers, NULL, 'A1');

// Add data rows
$rowNum = 2;
while ($row = $result->fetch_assoc()) {
    $total_price = ($row['quantity'] - $row['discounts']) * $row['price'];
    $total_tax = $row['quantity'] * $row['tax'];

    $data = [
        $row['order_id'],
        $row['product_id'],
        $row['customer_name'],
        $row['customer_phone'],
        $row['quantity'],
        $row['payment_method'],
        $row['user_id'],
        $row['discounts'],
        $row['product_name'],
        number_format($row['price'], 2),
        $row['tax'] . '%',
        number_format($total_price, 2),
        number_format($total_tax, 2),
        $row['created_at']
    ];

    $sheet->fromArray($data, NULL, 'A' . $rowNum++);
}

// Set response headers and output Excel file
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="orders.xlsx"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
