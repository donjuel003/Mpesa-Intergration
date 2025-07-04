<?php
// DB connection
function getConnection() {
    $conn = new mysqli("localhost", "root", "", "pos");
    if ($conn->connect_error) {
        die("Connection failed: " . $conn->connect_error);
    }
    return $conn;
}

// Handle new order
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['update_status']) && !isset($_POST['update_payment'])) {
    $conn = getConnection();

    if (
        isset($_POST['product_id'], $_POST['customer_name'], $_POST['customer_phone'],
              $_POST['quantity'], $_POST['payment_method'], $_POST['user_id'], $_POST['discounts'])
    ) {
        $product_id = (int)$_POST['product_id'];
        $customer_name = $conn->real_escape_string($_POST['customer_name']);
        $customer_phone = $conn->real_escape_string($_POST['customer_phone']);
        $quantity = (int)$_POST['quantity'];
        $payment_method = $conn->real_escape_string($_POST['payment_method']);
        $user_id = (int)$_POST['user_id'];
        $discounts = (int)$_POST['discounts'];

        // Insert into orders
        $stmt = $conn->prepare("INSERT INTO orders (customer_name, customer_phone, payment_method, user_id, status) VALUES (?, ?, ?, ?, 'pending')");
        $stmt->bind_param("sssi", $customer_name, $customer_phone, $payment_method, $user_id);
        $stmt->execute();
        $order_id = $stmt->insert_id;
        $stmt->close();

        // Insert into order_items
        $stmt = $conn->prepare("INSERT INTO order_items (order_id, product_id, quantity, discount) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("iiii", $order_id, $product_id, $quantity, $discounts);
        $stmt->execute();
        $stmt->close();

        $conn->close();
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    }
}

// Handle payment update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_payment'])) {
    $conn = getConnection();

    $order_id = (int)$_POST['order_id'];
    $payment_method = $conn->real_escape_string($_POST['payment_method']);

    $statusCheck = $conn->query("SELECT status FROM orders WHERE order_id = $order_id")->fetch_assoc();
    if ($statusCheck && $statusCheck['status'] === 'delivered' && $payment_method === 'Unpaid') {
        echo "<script>alert('Cannot set payment method to Unpaid for a delivered order.');</script>";
    } else {
        $stmt = $conn->prepare("UPDATE orders SET payment_method = ? WHERE order_id = ?");
        $stmt->bind_param("si", $payment_method, $order_id);
        $stmt->execute();
        $stmt->close();
    }

    $conn->close();
    header("Location: " . $_SERVER['PHP_SELF']);
    exit();
}

// Handle status update (including stock reduction)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    $conn = getConnection();

    $order_id = (int)$_POST['order_id'];
    $new_status = $conn->real_escape_string($_POST['status']);

    $res = $conn->query("SELECT payment_method, status FROM orders WHERE order_id = $order_id");
    $row = $res->fetch_assoc();
    $payment_method = $row['payment_method'];
    $current_status = $row['status'];

    if ($new_status === 'delivered' && $payment_method === 'Unpaid') {
        echo "<script>alert('Cannot mark as delivered. Payment is unpaid.');</script>";
    } else {
        if ($current_status !== 'delivered' && $new_status === 'delivered') {
            // reduce stock
            $items = $conn->query("SELECT product_id, quantity FROM order_items WHERE order_id = $order_id");
            while ($item = $items->fetch_assoc()) {
                $stmt = $conn->prepare("UPDATE products SET stock = stock - ? WHERE id = ?");
                $stmt->bind_param("ii", $item['quantity'], $item['product_id']);
                $stmt->execute();
                $stmt->close();
            }
        }

        $stmt = $conn->prepare("UPDATE orders SET status = ? WHERE order_id = ?");
        $stmt->bind_param("si", $new_status, $order_id);
        $stmt->execute();
        $stmt->close();
    }

    $conn->close();
    header("Location: " . $_SERVER['PHP_SELF']);
    exit();
}
?>



<!-- HTML continues here (unchanged) -->

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>POS System Dashboard</title>
    <link rel="stylesheet" href="styles.css" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        body, html {
            height: 100%;
            margin: 0;
            overflow-y: auto;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        th, td {
            padding: 8px 12px;
            border: 1px solid #ccc;
        }
        form {
            margin-bottom: 40px;
        }
        form input, form select {
            display: block;
            margin-bottom: 10px;
            padding: 6px;
            width: 100%;
            max-width: 300px;
        }
        form button {
            padding: 8px 16px;
        }
        .order-form-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 40px;
            max-width: 800px;
        }
        .order-form-column {
            display: flex;
            flex-direction: column;
        }
        .order-form-column label,
        .order-form-column input,
        .order-form-column select,
        .order-form-column button {
            margin-bottom: 10px;
        }
        .filter-section {
            margin: 20px 0;
        }
        .filter-section .filter-row {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            align-items: center;
        }

        .filter-row input,
        .filter-row select,
        .filter-row button {
            flex: 1 1 150px;
            max-width: 200px;
            padding: 6px;
        }

        .discount-check {
            display: flex;
            align-items: center;
            gap: 4px;
            white-space: nowrap;
        }
                .dropdown {
            position: relative;
        }
        .dropdown ul {
            left: 20px;
            top: 100%;
            background: #f8f8f8;
            list-style: none;
            padding: 5px;
        }
        .dropdown:hover ul {
            display: block;
        }
        .dropdown ul li a {
            display: block;
            padding: 5px 10px;
            color: #333;
            text-decoration: none;
        }
        .dropdown ul li a:hover {
            background: #e0e0e0;
        }
        .sidebar-menu .dropdown-menu {
            overflow: hidden;
            max-height: 0;
            opacity: 0;
            transition: max-height 0.6s ease, opacity 0.6s ease;
            flex-direction: column;
            background-color: #34495e;
            font-size: 0.9em;
            padding-left: 10px;
        }

        .sidebar-menu .dropdown.open .dropdown-menu {
            max-height: 600px;
            opacity: 1;
        }

        .sidebar-menu .dropdown-toggle {
            display: block;
            width: 100%;
            padding: 12px 20px;
            color: #fff;
            text-align: left;
            border: none;
            background-color: #2c3e50;
            cursor: pointer;
            font-size: 1em;
        }

        .sidebar-menu .dropdown-toggle:hover {
            background-color: #34495e;
        }

        .caret-icon {
            transition: transform 0.6s ease;
        }

        .dropdown.open .caret-icon {
            transform: rotate(180deg);
        }

        .sidebar-menu .dropdown-menu li a {
            padding: 8px 30px;
            color: #ccc;
            display: block;
        }

        .sidebar-menu .dropdown-menu li a:hover {
            background-color: #3e556e;
            color: #fff;
        }
    </style>
</head>
<body>
    <div id="wrapper">
        <aside id="sidebar">
            <div class="sidebar-header">
                <h1>POS Dashboard</h1>
            </div>
            <ul class="sidebar-menu">
                <li><a href="admin-dashboard.php" ><i class="fas fa-chart-line"></i> Dashboard</a></li>
                <li><a href="admin-orders.php" class="active"><i class="fas fa-receipt"></i> Orders</a></li>
                <li><a href="admin-sales.php" ><i class="fas fa-cash-register"></i> Sales</a></li>
                <li><a href="admin-products.php" ><i class="fas fa-box"></i> Products</a></li>
                <li class="dropdown open">
                    <li class="dropdown ">
                        <a href="#" class="dropdown-toggle">
                            <i class="fas fa-warehouse"></i> Inventory
                            <i class="fas fa-caret-down caret-icon" style="float: right;"></i>
                        </a>
                    <ul class="dropdown-menu">
                        <li><a href="admin-inventory.php">Inventory Management</a></li>
                        <li><a href="inventory-report.php">Inventory Report</a></li>
                        <li><a href="inventory-history.php">Inventory Logs</a></li>
                        <li><a href="update_stock.php">Update Stock</a></li>
                        <li><a href="check_low_stock.php">Low Stock Alert</a></li>
                        <li><a href="export_inventory_pdf.php">Export to PDF</a></li>
                        <li><a href="export_inventory_excel.php">Export to Excel</a></li>
                    </ul>
                </li>
                    <li class="dropdown ">
                    <a href="#" class="dropdown-toggle "> 
                        <i class="fas fa-truck"></i> Suppliers
                        <i class="fas fa-caret-down caret-icon" style="float: right;"></i>
                    </a>
                    <ul class="dropdown-menu">
                        <li><a href="admin-suppliers.php">Supplier List</a></li>
                        <li><a href="add-supplier.php">Add Supplier</a></li>
                        <li><a href="supply_invoices_list.php">Supply Invoice List</a></li>
                        <li><a href="add_supply_invoice.php">Add Supply Invoice</a></li>
                    </ul>
                </li>
                <li><a href="sales-report.php"><i class="fas fa-chart-bar"></i> Reports</a></li>
                <li><a href="admin-logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
            </ul>
        </aside>


        <div id="main-content">
            <header class="main-header">
                <h2>Admin Stats</h2>
                <div class="header-actions">
                    <button class="action-btn"><i class="fas fa-edit"></i> Edit</button>
                    <button class="action-btn primary-btn"><i class="fas fa-plus"></i> Add Widget</button>
                </div>
            </header>

            <main>
                <section id="sales">
                    <h2>Make a New Order</h2>
                    <form method="POST" action="">
                        <div class="order-form-grid">
                            <div class="order-form-column">
                                <label for="product_id">Product ID:</label>
                                <input type="number" name="product_id" required>
                                <label for="customer_name">Customer Name:</label>
                                <input type="text" name="customer_name" required>
                                <label for="customer_phone">Customer Phone:</label>
                                <input type="text" name="customer_phone" required>
                                <label for="quantity">Quantity:</label>
                                <input type="number" name="quantity" required>
                            </div>
                            <div class="order-form-column">
                                <label for="payment_method">Payment Method:</label>
                                <select name="payment_method" required>
                                    <option value="Mpesa">Mpesa</option>
                                    <option value="Cash">Cash</option>
                                    <option value="Unpaid">Unpaid</option>
                                </select>
                                <label for="user_id">User ID:</label>
                                <input type="number" name="user_id" required>
                                <label for="discounts">Discounts:</label>
                                <input type="number" name="discounts" value="0" required>
                                <button type="submit">Submit Order</button>
                            </div>
                        </div>
                    </form>

                    <h2>Filter Orders</h2>
                    <form method="GET" class="filter-section" id="filter-form">
                        <div class="filter-row">
                            <input type="text" name="customer_name" placeholder="Customer Name">
                            <input type="text" name="product_name" placeholder="Product Name">
                            <input type="text" name="user_id" placeholder="User ID">
                            <select name="payment_method">
                                <option value="">All Payments</option>
                                <option value="Mpesa">Mpesa</option>
                                <option value="Cash">Cash</option>
                                <option value="Unpaid">Unpaid</option>
                            </select>
                            <label class="discount-check">
                                <input type="checkbox" name="no_discount" value="1"> No Discount
                            </label>
                            <button type="submit">Apply Filter</button>
                            <button type="button" onclick="exportToPDF()">Export PDF</button>
                            <button type="button" onclick="exportToExcel()">Export Excel</button>

                        </div>
                    </form>

                    <script>
                    function exportToExcel() {
                        const form = document.getElementById('filter-form');
                        const params = new URLSearchParams(new FormData(form)).toString();
                        window.location.href = 'export-excel.php?' + params;
                    }

                    function exportToPDF() {
                        const form = document.getElementById('filter-form');
                        const params = new URLSearchParams(new FormData(form)).toString();
                        window.location.href = 'export-pdf.php?' + params;
                    }
                    </script>



                  
                        <tbody>
                            <?php
                                function renderGroupedOrdersTable($result) {
                                    $ordersById = [];
                                    $totalsById = [];

                                    while ($row = $result->fetch_assoc()) {
                                        $oid = $row['order_id'];
                                        if (!isset($ordersById[$oid])) {
                                            $ordersById[$oid] = [];
                                            $totalsById[$oid] = ['total_price' => 0, 'total_tax' => 0];
                                        }

                                        $line_total_price = ($row['quantity'] - $row['discount']) * $row['price'];
                                        $line_total_tax = $row['quantity'] * $row['tax'];

                                        $row['line_total_price'] = $line_total_price;
                                        $row['line_total_tax'] = $line_total_tax;

                                        $ordersById[$oid][] = $row;

                                        $totalsById[$oid]['total_price'] += $line_total_price;
                                        $totalsById[$oid]['total_tax'] += $line_total_tax;
                                    }

                                    foreach ($ordersById as $orderId => $lines) {
                                        $rowspan = count($lines);
                                        $first = $lines[0];

                                        echo "<tr>";
                                        echo "<td rowspan='$rowspan'>{$orderId}</td>";
                                        echo "<td>{$first['product_name']} (x{$first['quantity']})</td>";
                                        echo "<td rowspan='$rowspan'>{$first['customer_name']}</td>";
                                        echo "<td rowspan='$rowspan'>{$first['customer_phone']}</td>";
                                        echo "<td>{$first['quantity']}</td>";
                                        echo "<td rowspan='$rowspan'>
                                            <form method='POST' style='display:inline-flex; gap:4px;'>
                                                <input type='hidden' name='update_payment' value='1'>
                                                <input type='hidden' name='order_id' value='{$orderId}'>
                                                <select name='payment_method' onchange='this.form.submit()'>
                                                    <option value='Mpesa' " . ($first['payment_method'] === 'Mpesa' ? 'selected' : '') . ">Mpesa</option>
                                                    <option value='Cash' " . ($first['payment_method'] === 'Cash' ? 'selected' : '') . ">Cash</option>
                                                    <option value='Unpaid' " . ($first['payment_method'] === 'Unpaid' ? 'selected' : '') . ">Unpaid</option>
                                                </select>
                                            </form>
                                        </td>";
                                        echo "<td rowspan='$rowspan'>{$first['user_id']}</td>";
                                        echo "<td>{$first['discount']}</td>";
                                        echo "<td>{$first['price']}</td>";
                                        echo "<td>{$first['tax']}%</td>";
                                        echo "<td rowspan='$rowspan'>KES " . number_format($totalsById[$orderId]['total_price'], 2) . "</td>";
                                        echo "<td rowspan='$rowspan'>KES " . number_format($totalsById[$orderId]['total_tax'], 2) . "</td>";
                                        echo "<td rowspan='$rowspan'><img src='uploads/{$first['image']}' style='width:40px;'></td>";
                                        echo "<td rowspan='$rowspan'>{$first['created_at']}</td>";
                                        echo "<td rowspan='$rowspan'>
                                                <form method='POST' style='gap:4px;'>
                                                    <input type='hidden' name='order_id' value='{$orderId}'>
                                                    <select name='status'>
                                                        <option value='pending'" . ($first['status'] === 'pending' ? ' selected' : '') . ">Pending</option>
                                                        <option value='delivered'" . ($first['status'] === 'delivered' ? ' selected' : '') . ">Delivered</option>
                                                        <option value='cancelled'" . ($first['status'] === 'cancelled' ? ' selected' : '') . ">Cancelled</option>
                                                    </select>
                                                    <button type='submit' name='update_status'>Confirm</button>
                                                </form>
                                            </td>";
                                        echo "</tr>";

                                        for ($i = 1; $i < count($lines); $i++) {
                                            $line = $lines[$i];
                                            echo "<tr>";
                                            echo "<td>{$line['product_name']} (x{$line['quantity']})</td>";
                                            echo "<td>{$line['quantity']}</td>";
                                            echo "<td>{$line['discount']}</td>";
                                            echo "<td>{$line['price']}</td>";
                                            echo "<td>{$line['tax']}%</td>";
                                            echo "</tr>";
                                        }
                                    }
                                }
                                ?>

                        </tbody>
                    </table>

                    
                        <tbody>
                            <?php
                           $conn = getConnection();
                            $unpaid = $conn->query("
                                SELECT o.order_id, o.customer_name, o.customer_phone, o.payment_method, o.user_id, o.created_at, o.status,
                                    oi.product_id, oi.quantity, oi.discount,
                                    p.product_name, p.price, p.tax, p.image
                                FROM orders o
                                JOIN order_items oi ON o.order_id = oi.order_id
                                JOIN products p ON oi.product_id = p.id
                                WHERE o.status = 'Unpaid'
                                ORDER BY o.created_at DESC
                            ");
                            renderGroupedOrdersTable($unpaid);

                            while ($row = $unpaid->fetch_assoc()) {
                                $total_price = ($row['quantity'] - $row['discount']) * $row['price'];
                                $total_tax = $row['quantity'] * $row['tax'];

                                echo "<tr>
                                    <td>{$row['order_id']}</td>
                                    <td>{$row['product_name']}</td>
                                    <td>{$row['customer_name']}</td>
                                    <td>{$row['customer_phone']}</td>
                                    <td>{$row['quantity']}</td>
                                    <td>
                                        <form method='POST' style='display:inline-flex; gap:4px;'>
                                            <input type='hidden' name='update_payment' value='1'>
                                            <input type='hidden' name='order_id' value='{$row['order_id']}'>
                                            <select name='payment_method' onchange='this.form.submit()'>
                                                <option value='Mpesa' " . ($row['payment_method'] === 'Mpesa' ? 'selected' : '') . ">Mpesa</option>
                                                <option value='Cash' " . ($row['payment_method'] === 'Cash' ? 'selected' : '') . ">Cash</option>
                                                <option value='Unpaid' " . ($row['payment_method'] === 'Unpaid' ? 'selected' : '') . ">Unpaid</option>
                                            </select>
                                        </form>
                                    </td>
                                    <td>{$row['user_id']}</td>
                                    <td>{$row['discount']}</td>
                                    <td>{$row['product_name']}</td>
                                    <td>{$row['price']}</td>
                                    <td>{$row['tax']}%</td>
                                    <td>{$total_price}</td>
                                    <td>{$total_tax}</td>
                                    <td><img src='uploads/{$row['image']}' alt='Product Image' style='width:40px; height:auto;'></td>
                                    <td>{$row['created_at']}</td>
                                </tr>";
                            }
                            $conn->close();
                            ?>
                        </tbody>
                    </table>
                    <h2>Pending Orders</h2>
                    <table>
                        <thead>
                            <tr>
                                <th>Order ID</th><th>Product</th><th>Customer</th><th>Phone</th><th>Quantity</th>
                                <th>Payment</th><th>User ID</th><th>Discount</th><th>Price</th><th>Tax</th>
                                <th>Total Price</th><th>Total Tax</th><th>Image</th><th>Created At</th><th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $conn = new mysqli("localhost", "root", "", "pos");
                            if ($conn->connect_error) { die("Connection failed: " . $conn->connect_error); }
                            $conn = getConnection();
                            $pending = $conn->query("
                                SELECT o.order_id, o.customer_name, o.customer_phone, o.payment_method, o.user_id, o.created_at, o.status,
                                    oi.product_id, oi.quantity, oi.discount,
                                    p.product_name, p.price, p.tax, p.image
                                FROM orders o
                                JOIN order_items oi ON o.order_id = oi.order_id
                                JOIN products p ON oi.product_id = p.id
                                WHERE o.status = 'pending'
                                ORDER BY o.created_at DESC
                            ");
                            renderGroupedOrdersTable($pending);

                            while ($row = $pending->fetch_assoc()) {
                                $total_price = ($row['quantity'] - $row['discount']) * $row['price'];
                                $total_tax = $row['quantity'] * $row['tax'];
                                echo "<tr>
                                    <td>{$row['order_id']}</td>
                                    <td>{$row['product_name']}</td>
                                    <td>{$row['customer_name']}</td>
                                    <td>{$row['customer_phone']}</td>
                                    <td>{$row['quantity']}</td>
                                    <td>
                                        <form method='POST' style='display:inline-flex; gap:4px;'>
                                            <input type='hidden' name='update_payment' value='1'>
                                            <input type='hidden' name='order_id' value='{$row['order_id']}'>
                                            <select name='payment_method' onchange='this.form.submit()'>
                                                <option value='Mpesa' " . ($row['payment_method'] === 'Mpesa' ? 'selected' : '') . ">Mpesa</option>
                                                <option value='Cash' " . ($row['payment_method'] === 'Cash' ? 'selected' : '') . ">Cash</option>
                                                <option value='Unpaid' " . ($row['payment_method'] === 'Unpaid' ? 'selected' : '') . ">Unpaid</option>
                                            </select>
                                        </form>
                                    </td>
                                    <td>{$row['user_id']}</td>
                                    <td>{$row['discount']}</td>
                                    <td>{$row['price']}</td>
                                    <td>{$row['tax']}%</td>
                                    <td>{$total_price}</td>
                                    <td>{$total_tax}</td>
                                    <td><img src='uploads/{$row['image']}' style='width:40px;'></td>
                                    <td>{$row['created_at']}</td>
                                    <td>
                                        <form method='POST' style=' gap:4px;'>
                                            <input type='hidden' name='order_id' value='{$row['order_id']}'>
                                            <select name='status'>
                                                <option value='pending'" . ($row['status'] === 'pending' ? ' selected' : '') . ">Pending</option>
                                                <option value='delivered'" . ($row['status'] === 'delivered' ? ' selected' : '') . ">Delivered</option>
                                                <option value='cancelled'" . ($row['status'] === 'cancelled' ? ' selected' : '') . ">Cancelled</option>
                                            </select>
                                            <button type='submit' name='update_status'>Confirm</button>
                                        </form>
                                    </td>
                                </tr>";
                            }
                            ?>
                        </tbody>
                    </table>

                    <h2>Cancelled Orders</h2>
                    <table>
                        <thead>
                            <tr>
                                <th>Order ID</th><th>Product</th><th>Customer</th><th>Phone</th><th>Quantity</th>
                                <th>Payment</th><th>User ID</th><th>Discount</th><th>Price</th><th>Tax</th>
                                <th>Total Price</th><th>Total Tax</th><th>Image</th><th>Created At</th><th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $cancelled = $conn->query("
                                SELECT 
                                    o.order_id, o.customer_name, o.customer_phone, o.payment_method, o.user_id, o.created_at, o.status,
                                    oi.product_id, oi.quantity, oi.discount,
                                    p.product_name, p.price, p.tax, p.image
                                FROM orders o
                                JOIN order_items oi ON o.order_id = oi.order_id
                                JOIN products p ON oi.product_id = p.id
                                WHERE o.status = 'cancelled'
                                ORDER BY o.created_at DESC
                            ");

                            while ($row = $cancelled->fetch_assoc()) {
                                $total_price = ($row['quantity'] - $row['discount']) * $row['price'];
                                $total_tax = $row['quantity'] * $row['tax'];
                                echo "<tr>
                                    <td>{$row['order_id']}</td>
                                    <td>{$row['product_name']}</td>
                                    <td>{$row['customer_name']}</td>
                                    <td>{$row['customer_phone']}</td>
                                    <td>{$row['quantity']}</td>
                                    <td>
                                    <form method='POST' style='display:inline-flex; gap:4px;'>
                                        <input type='hidden' name='update_payment' value='1'>
                                        <input type='hidden' name='order_id' value='{$row['order_id']}'>
                                        <select name='payment_method' onchange='this.form.submit()'>
                                            <option value='Mpesa' " . ($row['payment_method'] === 'Mpesa' ? 'selected' : '') . ">Mpesa</option>
                                            <option value='Cash' " . ($row['payment_method'] === 'Cash' ? 'selected' : '') . ">Cash</option>
                                            <option value='Unpaid' " . ($row['payment_method'] === 'Unpaid' ? 'selected' : '') . ">Unpaid</option>
                                        </select>
                                    </form>
                                </td>
                                    <td>{$row['user_id']}</td>
                                    <td>{$row['discount']}</td>
                                    <td>{$row['price']}</td>
                                    <td>{$row['tax']}%</td>
                                    <td>{$total_price}</td>
                                    <td>{$total_tax}</td>
                                    <td><img src='uploads/{$row['image']}' style='width:40px;'></td>
                                    <td>{$row['created_at']}</td>
                                    <td>
                                        <form method='POST' style=' gap:4px;'>
                                            <input type='hidden' name='order_id' value='{$row['order_id']}'>
                                            <select name='status'>
                                                <option value='pending'" . ($row['status'] === 'pending' ? ' selected' : '') . ">Pending</option>
                                                <option value='delivered'" . ($row['status'] === 'delivered' ? ' selected' : '') . ">Delivered</option>
                                                <option value='cancelled'" . ($row['status'] === 'cancelled' ? ' selected' : '') . ">Cancelled</option>
                                            </select>
                                            <button type='submit' name='update_status'>Confirm</button>
                                        </form>
                                    </td>
                                </tr>";
                            }
                            $conn->close();
                            ?>
                        </tbody>
                    </table>

                </section>
            </main>
        </div>
    </div>
       <script>
    document.addEventListener("DOMContentLoaded", function () {
        const toggles = document.querySelectorAll(".dropdown-toggle");

        toggles.forEach(toggle => {
            toggle.addEventListener("click", function (e) {
                e.preventDefault();
                const dropdown = this.closest(".dropdown");
                dropdown.classList.toggle("open");

                document.querySelectorAll(".dropdown").forEach(other => {
                    if (other !== dropdown) {
                        other.classList.remove("open");
                    }
                });
            });
        });
    });
    </script>
</body>
</html>
