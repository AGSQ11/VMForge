<?php
namespace VMForge\Controllers;

use VMForge\Core\Auth;
use VMForge\Core\View;
use VMForge\Core\Security;
use VMForge\Models\Customer;
use VMForge\Models\Invoice;
use VMForge\Models\Product;
use VMForge\Models\Subscription;

class BillingController
{
    public function index()
    {
        $user = Auth::require();
        if (!Policy::can('billing.view')) {
            http_response_code(403);
            View::render('Forbidden', '<div class="card"><h2>403 Forbidden</h2><p>You do not have permission to access billing.</p></div>');
            return;
        }
        $customer = Customer::findByUserId($user['id']);
        $invoices = $customer ? Invoice::findByCustomerId($customer['id']) : [];

        $html = '<div class="card"><h2>Billing Overview</h2>';
        if (!$customer) {
            $html .= '<p>You have no billing information on file.</p>';
        } else {
            $html .= '<h3>Your Invoices</h3>';
            if (empty($invoices)) {
                $html .= '<p>No invoices found.</p>';
            } else {
                $html .= '<table class="table"><thead><tr><th>ID</th><th>Date</th><th>Due Date</th><th>Total</th><th>Status</th><th>Action</th></tr></thead><tbody>';
                foreach ($invoices as $invoice) {
                    $html .= '<tr>';
                    $html .= '<td>' . (int)$invoice['id'] . '</td>';
                    $html .= '<td>' . htmlspecialchars($invoice['issue_date']) . '</td>';
                    $html .= '<td>' . htmlspecialchars($invoice['due_date']) . '</td>';
                    $html .= '<td>$' . number_format((float)$invoice['total'], 2) . '</td>';
                    $html .= '<td>' . htmlspecialchars($invoice['status']) . '</td>';
                    $html .= '<td><a href="/billing/invoice?id='.(int)$invoice['id'].'">View</a></td>';
                    $html .= '</tr>';
                }
                $html .= '</tbody></table>';
            }
        }
        $html .= '</div>';
        View::render('Billing', $html);
    }

    public function products()
    {
        Auth::require();
        $products = Product::findAll();

        $html = '<div class="card"><h2>Order New Service</h2>';
        if (empty($products)) {
            $html .= '<p>No products available at this time.</p>';
        } else {
            $html .= '<style>.product-list { display: grid; grid-template-columns: repeat(auto-fill, minmax(250px, 1fr)); gap: 1rem; } .product-item { border: 1px solid #ccc; padding: 1rem; }</style>';
            $html .= '<div class="product-list">';
            foreach ($products as $product) {
                $csrf = Security::csrfToken();
                $html .= '<div class="product-item">';
                $html .= '<h3>' . htmlspecialchars($product['name']) . '</h3>';
                $html .= '<p>' . htmlspecialchars($product['description']) . '</p>';
                $html .= '<p><strong>Price: $' . number_format((float)$product['price'], 2) . '</strong></p>';
                $html .= '<form method="post" action="/billing/subscribe">';
                $html .= '<input type="hidden" name="csrf" value="'.$csrf.'">';
                $html .= '<input type="hidden" name="product_id" value="' . (int)$product['id'] . '">';
                $html .= '<button type="submit">Order Now</button>';
                $html .= '</form>';
                $html .= '</div>';
            }
            $html .= '</div>';
        }
        $html .= '</div>';
        View::render('Products', $html);
    }

    public function subscribe()
    {
        $user = Auth::require();
        Security::requireCsrf($_POST['csrf'] ?? null);
        $productId = (int)($_POST['product_id'] ?? 0);

        if (!$productId) {
            header('Location: /billing/products');
            exit;
        }

        $customer = Customer::findByUserId($user['id']);
        if (!$customer) {
            // For simplicity, create a customer record automatically.
            // In a real application, we would ask for more details.
            $customerId = Customer::create($user['id'], []);
        } else {
            $customerId = $customer['id'];
        }

        // For now, next due date is 1 month from now.
        $nextDueDate = date('Y-m-d', strtotime('+1 month'));
        Subscription::create($customerId, $productId, $nextDueDate);

        // Redirect to billing overview
        header('Location: /billing');
        exit;
    }
}
