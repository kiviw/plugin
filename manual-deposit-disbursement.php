<?php
/**
 * Plugin Name: Manual Deposit and KSH Disbursement
 * Plugin URI: Your plugin website URL
 * Description: A simple plugin for manual deposit confirmation and KSH disbursement.
 * Version: 1.0.0
 * Author: Your Name
 * Author URI: Your website URL
 * Text Domain: manual-deposit-disbursement
 * License: GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 */

// Create custom database table on plugin activation
function create_deposit_requests_table() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'deposit_requests';

    // Check if the table exists, if not, create it
    if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            deposit_address varchar(255) NOT NULL,
            amount_in_wld float NOT NULL,
            phone varchar(255) NOT NULL,
            mpesa_name varchar(255) NOT NULL,
            tx_hash varchar(255) NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'Unconfirmed',
            PRIMARY KEY  (id)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
}
register_activation_hook(__FILE__, 'create_deposit_requests_table');

// Enqueue script to handle real-time conversion
function manual_deposit_disbursement_enqueue_scripts() {
    wp_enqueue_script('manual-deposit-conversion-script', plugin_dir_url(__FILE__) . 'js/conversion.js', array('jquery'), '1.0.0', true);
}
add_action('wp_enqueue_scripts', 'manual_deposit_disbursement_enqueue_scripts');

// Register shortcode to display the deposit form
function manual_deposit_form_shortcode() {
    ob_start();
    $deposit_address = '0xbe5d9b4f0b61ed76bbfa821ea465e0c4179f0684'; // Replace this with the actual deposit address

    if (isset($_POST['submit_deposit'])) {
        if (isset($_POST['manual_deposit_nonce']) && wp_verify_nonce($_POST['manual_deposit_nonce'], 'manual_deposit_nonce')) {
            process_deposit_submission();
        }
    }
    ?>
    <style>
        .manual-deposit-form {
            max-width: 600px;
            margin: 0 auto;
            padding: 20px;
            border: 1px solid #ccc;
            border-radius: 5px;
        }
        .manual-deposit-form label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
        }
        .manual-deposit-form input[type="number"],
        .manual-deposit-form input[type="text"],
        .manual-deposit-form input[type="submit"] {
            width: 100%;
            padding: 10px;
            margin-bottom: 10px;
            border: 1px solid #ccc;
            border-radius: 5px;
        }
        .manual-deposit-form input[type="submit"] {
            background-color: #0073aa;
            color: #fff;
            cursor: pointer;
        }
        .manual-deposit-form table {
            width: 100%;
            border-collapse: collapse;
        }
        .manual-deposit-form table th,
        .manual-deposit-form table td {
            padding: 8px;
            border: 1px solid #ccc;
            text-align: left;
        }
        @media screen and (max-width: 600px) {
            .manual-deposit-form {
                padding: 10px;
            }
            .manual-deposit-form input[type="number"],
            .manual-deposit-form input[type="text"],
            .manual-deposit-form input[type="submit"] {
                padding: 5px;
            }
            .manual-deposit-form table th,
            .manual-deposit-form table td {
                padding: 5px;
            }
        }
    </style>
    <div class="manual-deposit-form">
        <p>Copy the WLD deposit address below and use it to send your WLD from an external wallet:</p>
        <div class="deposit-address"><?php echo $deposit_address; ?></div>

        <form method="post">
            <?php wp_nonce_field('manual_deposit_nonce'); ?>
            <label for="amount_in_wld">Amount in WLD Sent:</label>
            <input type="number" id="amount_in_wld" name="amount_in_wld" min="0" step="1" required />

            <label for="amount_in_ksh">Equivalent Amount in KSH:</label>
            <input type="text" id="amount_in_ksh" name="amount_in_ksh" readonly />

            <label for="phone">Your Phone Number (MPESA):</label>
            <input type="text" id="phone" name="phone" required />

            <label for="mpesa_name">Your MPESA Name:</label>
            <input type="text" id="mpesa_name" name="mpesa_name" required />

            <label for="tx_hash">Transaction Hash:</label>
            <input type="text" id="tx_hash" name="tx_hash" required />

            <input type="submit" value="Submit Deposit Request" name="submit_deposit" />
        </form>
    </div>
    <?php
    return ob_get_clean();
}
add_shortcode('manual_deposit_form', 'manual_deposit_form_shortcode');

// Process deposit form submission
function process_deposit_submission() {
    if (isset($_SESSION['form_submitted'])) {
        return;
    }

    $deposit_address = '0xbe5d9b4f0b61ed76bbfa821ea465e0c4179f0684'; // Replace this with the actual deposit address
    $amount_in_wld = isset($_POST['amount_in_wld']) ? floatval($_POST['amount_in_wld']) : 0;
    $phone_number = isset($_POST['phone']) ? sanitize_text_field($_POST['phone']) : '';
    $mpesa_name = isset($_POST['mpesa_name']) ? sanitize_text_field($_POST['mpesa_name']) : '';
    $tx_hash = isset($_POST['tx_hash']) ? sanitize_text_field($_POST['tx_hash']) : '';

    if ($amount_in_wld <= 0 || empty($phone_number) || empty($mpesa_name) || empty($tx_hash)) {
        echo '<p>Invalid input. Please enter valid WLD amount, phone number, MPESA name, and Transaction Hash.</p>';
        return;
    }

    // Check if the transaction hash is unique
    global $wpdb;
    $table_name = $wpdb->prefix . 'deposit_requests';
    $existing_transaction = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE tx_hash = %s", $tx_hash));

    if ($existing_transaction) {
        echo '<p>Transaction Hash already used. Please provide a unique Transaction Hash.</p>';
        return;
    }

    $amount_in_ksh = $amount_in_wld * 210; // Conversion rate: 1 WLD = 210 KSH

    $data = array(
        'deposit_address' => $deposit_address,
        'amount_in_wld' => $amount_in_wld,
        'amount_in_ksh' => $amount_in_ksh,
        'phone' => $phone_number,
        'mpesa_name' => $mpesa_name,
        'tx_hash' => $tx_hash,
    );

    $wpdb->insert($table_name, $data);

    // For this example, we'll just show a success message
    echo '<p>Your deposit request has been submitted. Please wait for confirmation.</p>';
    $_SESSION['form_submitted'] = true;
}

// Admin page to manage deposit requests
function manual_deposit_admin_page() {
    if (!current_user_can('manage_options')) {
        return;
    }

    if (isset($_GET['action']) && $_GET['action'] === 'confirm' && isset($_GET['id'])) {
        confirm_manual_deposit($_GET['id']);
    }
    ?>
    <div class="wrap">
        <h1>Deposit Requests</h1>
        <?php
        // Add your custom code here to display and manage deposit requests
        // You can display a table of pending requests, manually confirm deposits, and disburse KSH
        // Update the database accordingly once you confirm and disburse KSH
        // For this example, we'll show the pending deposit requests for the admin
        echo manual_deposit_transactions_shortcode();
        ?>
    </div>
    <?php
}

// Hook the admin page function to an action
add_action('admin_menu', 'register_manual_deposit_admin_page');

// Register the admin page
function register_manual_deposit_admin_page() {
    add_menu_page(
        'Deposit Requests',
        'Deposit Requests',
        'manage_options',
        'manual_deposit_admin',
        'manual_deposit_admin_page',
        'dashicons-money',
        30
    );
}

// Register shortcode to display the deposit transactions table for admin
function manual_deposit_transactions_shortcode() {
    ob_start();
    global $wpdb;
    $table_name = $wpdb->prefix . 'deposit_requests';
    $transactions = $wpdb->get_results("SELECT * FROM $table_name ORDER BY id DESC");

    if ($transactions) {
        echo '<table class="manual-deposit-transactions">';
        echo '<thead><tr><th>Name</th><th>Amount in KSH</th><th>Status</th></tr></thead>';
        echo '<tbody>';
        foreach ($transactions as $transaction) {
            echo '<tr>';
            echo '<td>' . $transaction->mpesa_name . '</td>';
            echo '<td>' . $transaction->amount_in_ksh . '</td>';
            echo '<td>' . $transaction->status . '</td>';
            echo '</tr>';
        }
        echo '</tbody>';
        echo '</table>';
    } else {
        echo '<p>No deposit transactions found.</p>';
    }

    return ob_get_clean();
}
add_shortcode('manual_deposit_transactions', 'manual_deposit_transactions_shortcode');

// Admin page actions
add_action('admin_init', 'manual_deposit_admin_actions');
function manual_deposit_admin_actions() {
    if (isset($_GET['page']) && $_GET['page'] === 'manual_deposit_admin') {
        if (isset($_GET['action']) && $_GET['action'] === 'confirm' && isset($_GET['id'])) {
            confirm_manual_deposit($_GET['id']);
        }
    }
}

// Function to manually confirm a deposit
function confirm_manual_deposit($id) {
    if (!current_user_can('manage_options')) {
        return;
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'deposit_requests';
    $wpdb->update($table_name, array('status' => 'Confirmed'), array('id' => $id));

    // Redirect back to the admin page after confirming the deposit
    wp_redirect(admin_url('admin.php?page=manual_deposit_admin'));
    exit();
}

// Register shortcode to display the deposit transactions table for frontend users
function manual_deposit_transactions_user_shortcode() {
    ob_start();
    global $wpdb;
    $table_name = $wpdb->prefix . 'deposit_requests';
    $transactions = $wpdb->get_results("SELECT mpesa_name, amount_in_ksh, status FROM $table_name WHERE status IN ('Unconfirmed', 'Confirmed') ORDER BY id DESC");

    if ($transactions) {
        echo '<table class="manual-deposit-transactions-user">';
        echo '<thead><tr><th>Name</th><th>Amount in KSH</th><th>Status</th></tr></thead>';
        echo '<tbody>';
        foreach ($transactions as $transaction) {
            echo '<tr>';
            echo '<td>' . $transaction->mpesa_name . '</td>';
            echo '<td>' . $transaction->amount_in_ksh . '</td>';
            echo '<td>' . $transaction->status . '</td>';
            echo '</tr>';
        }
        echo '</tbody>';
        echo '</table>';
    } else {
        echo '<p>No deposit transactions found.</p>';
    }

    return ob_get_clean();
}
add_shortcode('manual_deposit_transactions_user', 'manual_deposit_transactions_user_shortcode');
