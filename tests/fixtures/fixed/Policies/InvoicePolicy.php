<?php

namespace App\Policies;

use App\Models\Invoice;
use App\Models\User;

// Support staff read invoices to answer customers; only admins change them.
class InvoicePolicy
{
    public function viewAny(User $user): bool
    {
        return in_array($user->role, ['admin', 'support'], true);
    }

    public function view(User $user, Invoice $invoice): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, Invoice $invoice): bool
    {
        return $user->isAdmin();
    }

    public function delete(User $user, Invoice $invoice): bool
    {
        return $user->isAdmin();
    }

    public function deleteAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function refund(User $user, Invoice $invoice): bool
    {
        return $user->isAdmin() && $invoice->status === 'paid';
    }
}
