<?php

namespace App\Http\Controllers\Api\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\PaymentMethodTemplate;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PaymentMethodTemplateController extends Controller
{
    /**
     * List all payment method templates (Super Admin)
     */
    public function index(Request $request)
    {
        $query = PaymentMethodTemplate::query();

        // Filter by type if provided
        if ($request->has('type') && $request->type !== 'all') {
            $query->where('type', $request->type);
        }

        // Filter by active status if provided
        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $templates = $query->ordered()->get();

        return response()->json([
            'success' => true,
            'data' => $templates,
        ]);
    }

    /**
     * Create a new payment method template
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:50',
            'type' => 'required|in:wallet,bank,crypto',
            'currency' => 'required|string|size:3',
            'input_type' => 'required|in:phone,email,account_number,crypto_address',
            'input_label' => 'required|string|max:50',
            'icon' => 'nullable|string|max:100',
            'fee' => 'nullable|numeric|min:0',
            'min_amount' => 'nullable|numeric|min:0',
            'max_amount' => 'nullable|numeric|min:0',
            'is_active' => 'nullable|boolean',
            'sort_order' => 'nullable|integer|min:0',
        ]);

        // Check for duplicate
        $exists = PaymentMethodTemplate::where('name', $validated['name'])
            ->where('currency', $validated['currency'])
            ->where('type', $validated['type'])
            ->exists();

        if ($exists) {
            return response()->json([
                'success' => false,
                'message' => 'Payment method template already exists',
            ], 422);
        }

        $template = PaymentMethodTemplate::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Payment method template created',
            'data' => $template,
        ], 201);
    }

    /**
     * Update an existing payment method template
     */
    public function update(Request $request, PaymentMethodTemplate $template)
    {
        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:50',
            'type' => 'sometimes|required|in:wallet,bank,crypto',
            'currency' => 'sometimes|required|string|size:3',
            'input_type' => 'sometimes|required|in:phone,email,account_number,crypto_address',
            'input_label' => 'sometimes|required|string|max:50',
            'icon' => 'nullable|string|max:100',
            'fee' => 'nullable|numeric|min:0',
            'min_amount' => 'nullable|numeric|min:0',
            'max_amount' => 'nullable|numeric|min:0',
            'is_active' => 'nullable|boolean',
            'sort_order' => 'nullable|integer|min:0',
        ]);

        // Check for duplicate (excluding current)
        if (isset($validated['name']) || isset($validated['currency']) || isset($validated['type'])) {
            $name = $validated['name'] ?? $template->name;
            $currency = $validated['currency'] ?? $template->currency;
            $type = $validated['type'] ?? $template->type;

            $exists = PaymentMethodTemplate::where('name', $name)
                ->where('currency', $currency)
                ->where('type', $type)
                ->where('id', '!=', $template->id)
                ->exists();

            if ($exists) {
                return response()->json([
                    'success' => false,
                    'message' => 'Payment method template with this combination already exists',
                ], 422);
            }
        }

        $template->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Payment method template updated',
            'data' => $template,
        ]);
    }

    /**
     * Delete a payment method template
     */
    public function destroy(PaymentMethodTemplate $template)
    {
        // Check if any user payment methods are using this template
        $usageCount = $template->userMethods()->count();

        if ($usageCount > 0) {
            return response()->json([
                'success' => false,
                'message' => "Cannot delete: {$usageCount} user(s) are using this payment method",
            ], 422);
        }

        $template->delete();

        return response()->json([
            'success' => true,
            'message' => 'Payment method template deleted',
        ]);
    }

    /**
     * Toggle active status
     */
    public function toggleActive(PaymentMethodTemplate $template)
    {
        $template->update(['is_active' => !$template->is_active]);

        return response()->json([
            'success' => true,
            'message' => 'Status updated',
            'data' => $template,
        ]);
    }

    /**
     * Reorder templates
     */
    public function reorder(Request $request)
    {
        $validated = $request->validate([
            'orders' => 'required|array',
            'orders.*.id' => 'required|exists:payment_method_templates,id',
            'orders.*.sort_order' => 'required|integer|min:0',
        ]);

        foreach ($validated['orders'] as $order) {
            PaymentMethodTemplate::where('id', $order['id'])
                ->update(['sort_order' => $order['sort_order']]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Order updated',
        ]);
    }

    /**
     * Get active templates for users (Public API)
     */
    public function getActive()
    {
        $templates = PaymentMethodTemplate::active()
            ->ordered()
            ->get(['id', 'name', 'type', 'currency', 'input_type', 'input_label', 'icon', 'fee', 'min_amount', 'max_amount']);

        return response()->json([
            'success' => true,
            'data' => $templates,
        ]);
    }
}
