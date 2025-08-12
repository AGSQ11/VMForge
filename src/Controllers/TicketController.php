<?php
namespace VMForge\Controllers;

use VMForge\Core\Auth;
use VMForge\Core\View;
use VMForge\Core\Security;
use VMForge\Core\Policy;
use VMForge\Models\Ticket;
use VMForge\Models\TicketReply;

class TicketController
{
    // Client-facing: list my tickets
    public function index()
    {
        $user = Auth::require();
        $tickets = Ticket::findByUserId($user['id']);

        $rows = '';
        foreach ($tickets as $ticket) {
            $rows .= '<tr>';
            $rows .= '<td><a href="/tickets/show?id='.(int)$ticket['id'].'">#'.(int)$ticket['id'].'</a></td>';
            $rows .= '<td>' . htmlspecialchars($ticket['subject']) . '</td>';
            $rows .= '<td>' . htmlspecialchars($ticket['status']) . '</td>';
            $rows .= '<td>' . htmlspecialchars($ticket['priority']) . '</td>';
            $rows .= '<td>' . htmlspecialchars($ticket['updated_at']) . '</td>';
            $rows .= '</tr>';
        }

        $html = '<div class="card"><h2>My Support Tickets</h2>';
        $html .= '<p><a href="/tickets/new">Create New Ticket</a></p>';
        $html .= '<table class="table"><thead><tr><th>ID</th><th>Subject</th><th>Status</th><th>Priority</th><th>Last Updated</th></tr></thead><tbody>' . $rows . '</tbody></table>';
        $html .= '</div>';
        View::render('My Support Tickets', $html);
    }

    // Client-facing: show new ticket form
    public function create()
    {
        $user = Auth::require();
        $csrf = Security::csrfToken();
        $html = '<div class="card"><h2>New Support Ticket</h2>';
        $html .= '<form method="post" action="/tickets/new">';
        $html .= '<input type="hidden" name="csrf" value="' . $csrf . '">';
        $html .= '<label for="subject">Subject</label><input type="text" name="subject" id="subject" required>';
        $html .= '<label for="priority">Priority</label><select name="priority" id="priority"><option value="low">Low</option><option value="medium" selected>Medium</option><option value="high">High</option></select>';
        $html .= '<label for="message">Message</label><textarea name="message" id="message" rows="8" required></textarea>';
        $html .= '<button type="submit">Create Ticket</button>';
        $html .= '</form></div>';
        View::render('New Support Ticket', $html);
    }

    // Client-facing: store new ticket
    public function store()
    {
        $user = Auth::require();
        Security::requireCsrf($_POST['csrf'] ?? null);
        $subject = trim($_POST['subject'] ?? '');
        $message = trim($_POST['message'] ?? '');
        $priority = $_POST['priority'] ?? 'medium';
        if (empty($subject) || empty($message)) {
            header('Location: /tickets/new');
            return;
        }
        $ticketId = Ticket::create($user['id'], $subject, $message, $priority);
        header('Location: /tickets/show?id=' . $ticketId);
        exit;
    }

    // Client/Admin: show a single ticket
    public function show()
    {
        $user = Auth::require();
        $ticketId = (int)($_GET['id'] ?? 0);
        $ticket = Ticket::findById($ticketId);

        // Security check: user must be owner or admin
        if (!$ticket || ($ticket['user_id'] != $user['id'] && !Policy::can('tickets.manage'))) {
            http_response_code(404);
            View::render('Not Found', '<div class="card"><h2>404 Not Found</h2><p>Ticket not found.</p></div>');
            return;
        }

        $replies = TicketReply::findByTicketId($ticketId);

        $html = '<div class="card"><h2>' . htmlspecialchars($ticket['subject']) . '</h2>';
        $html .= '<p><strong>Status:</strong> ' . htmlspecialchars($ticket['status']) . ' | <strong>Priority:</strong> ' . htmlspecialchars($ticket['priority']) . '</p>';
        $html .= '</div>';

        foreach ($replies as $reply) {
            $sender = ($reply['user_id'] === $user['id']) ? 'You' : htmlspecialchars($reply['email']);
            $html .= '<div class="card"><strong>' . $sender . '</strong> said:<p>' . nl2br(htmlspecialchars($reply['message'])) . '</p><small>Posted on: ' . htmlspecialchars($reply['created_at']) . '</small></div>';
        }

        $csrf = Security::csrfToken();
        $html .= '<div class="card"><h3>Reply to Ticket</h3>';
        $html .= '<form method="post" action="/tickets/reply">';
        $html .= '<input type="hidden" name="csrf" value="' . $csrf . '">';
        $html .= '<input type="hidden" name="ticket_id" value="' . $ticketId . '">';
        $html .= '<textarea name="message" rows="8" required></textarea>';
        $html .= '<button type="submit">Submit Reply</button>';
        $html .= '</form></div>';

        View::render('View Ticket #' . $ticketId, $html);
    }

    // Client/Admin: store a reply
    public function reply()
    {
        $user = Auth::require();
        Security::requireCsrf($_POST['csrf'] ?? null);
        $ticketId = (int)($_POST['ticket_id'] ?? 0);
        $message = trim($_POST['message'] ?? '');
        $ticket = Ticket::findById($ticketId);

        // Security check
        if (empty($message) || !$ticket || ($ticket['user_id'] != $user['id'] && !Policy::can('tickets.manage'))) {
            http_response_code(400);
            echo 'Invalid request';
            return;
        }

        TicketReply::create($ticketId, $user['id'], $message);
        header('Location: /tickets/show?id=' . $ticketId);
        exit;
    }

    // Admin-facing: list all tickets
    public function adminIndex()
    {
        Auth::require();
        if (!Policy::can('tickets.manage')) {
            http_response_code(403);
            View::render('Forbidden', '<div class="card"><h2>403 Forbidden</h2><p>You do not have permission to manage tickets.</p></div>');
            return;
        }

        $tickets = Ticket::findAll();

        $rows = '';
        foreach ($tickets as $ticket) {
            $rows .= '<tr>';
            $rows .= '<td><a href="/tickets/show?id='.(int)$ticket['id'].'">#'.(int)$ticket['id'].'</a></td>';
            $rows .= '<td>' . htmlspecialchars($ticket['subject']) . '</td>';
            $rows .= '<td>' . htmlspecialchars($ticket['email']) . '</td>';
            $rows .= '<td>' . htmlspecialchars($ticket['status']) . '</td>';
            $rows .= '<td>' . htmlspecialchars($ticket['priority']) . '</td>';
            $rows .= '<td>' . htmlspecialchars($ticket['updated_at']) . '</td>';
            $rows .= '</tr>';
        }

        $html = '<div class="card"><h2>All Support Tickets</h2>';
        $html .= '<table class="table"><thead><tr><th>ID</th><th>Subject</th><th>User</th><th>Status</th><th>Priority</th><th>Last Updated</th></tr></thead><tbody>' . $rows . '</tbody></table>';
        $html .= '</div>';
        View::render('All Support Tickets', $html);
    }
}
