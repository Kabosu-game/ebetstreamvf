<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\PaymentMethod;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

class AdminPaymentMethodController extends Controller
{
    /**
     * Vérifie et crée la table si elle n'existe pas
     */
    private function ensureTableExists()
    {
        if (!Schema::hasTable('payment_methods')) {
            Schema::create('payment_methods', function ($table) {
                $table->id();
                $table->string('name');
                $table->text('description')->nullable();
                $table->enum('type', ['deposit', 'withdrawal']);
                $table->string('method_key');
                $table->boolean('is_active')->default(true);
                $table->decimal('min_amount', 10, 2)->nullable();
                $table->decimal('max_amount', 10, 2)->nullable();
                $table->decimal('fee_percentage', 5, 2)->nullable();
                $table->decimal('fee_fixed', 10, 2)->nullable();
                $table->string('crypto_address')->nullable();
                $table->string('crypto_network')->nullable();
                $table->string('mobile_money_provider')->nullable();
                $table->string('bank_name')->nullable();
                $table->timestamps();
            });
            
            // Insérer les méthodes par défaut
            $this->seedDefaultMethods();
        }
    }
    
    /**
     * Insère les méthodes de paiement par défaut
     */
    private function seedDefaultMethods()
    {
        $methods = [
            ['USDT (TRC20)', 'Dépôt via USDT sur le réseau TRON', 'deposit', 'crypto', 1, 5, 10000, 0, null, 'TSf7x19gfn72Jk4Ah4RWVYuGxvYt5HMWqc', 'TRON (TRC20)', null, null],
            ['USDT (ERC20)', 'Dépôt via USDT sur le réseau Ethereum', 'deposit', 'crypto', 1, 5, 10000, 0, null, '0xAbc123Def456Ghi789Jkl0Mno1Pqr2Stu3Vwx4Yz5', 'Ethereum (ERC20)', null, null],
            ['Bitcoin (BTC)', 'Dépôt via Bitcoin', 'deposit', 'crypto', 1, 20, 20000, 1, null, 'bc1qxy2kgdygjrsqtzq2n0yrf2493p83kkfjhx0wlh', 'Bitcoin', null, null],
            ['Ethereum (ETH)', 'Dépôt via Ethereum', 'deposit', 'crypto', 1, 10, 15000, 0.5, null, '0x742d35Cc6634C0532925a3b844Bc454e4438f444', 'Ethereum', null, null],
            ['Cash via Agent', 'Dépôt en espèces via un agent de recharge', 'deposit', 'cash', 1, 1, 5000, null, 0.5, null, null, null, null],
            ['USDT (TRC20)', 'Retrait via USDT sur le réseau TRON', 'withdrawal', 'crypto', 1, 10, 50000, 0.5, 1, null, 'TRON (TRC20)', null, null],
            ['USDT (ERC20)', 'Retrait via USDT sur le réseau Ethereum', 'withdrawal', 'crypto', 1, 10, 50000, 0.8, 2, null, 'Ethereum (ERC20)', null, null],
            ['Bitcoin (BTC)', 'Retrait via Bitcoin', 'withdrawal', 'crypto', 1, 50, 100000, 1.5, 5, null, 'Bitcoin', null, null],
            ['Ethereum (ETH)', 'Retrait via Ethereum', 'withdrawal', 'crypto', 1, 30, 80000, 1.2, 3, null, 'Ethereum', null, null],
            ['Virement Bancaire', 'Retrait par virement bancaire', 'withdrawal', 'bank_transfer', 1, 20, 25000, null, 2, null, null, null, 'Any Bank'],
            ['MTN Mobile Money', 'Retrait via MTN Mobile Money', 'withdrawal', 'mobile_money', 1, 5, 1000, 1, null, null, null, 'MTN', null],
            ['Orange Money', 'Retrait via Orange Money', 'withdrawal', 'mobile_money', 1, 5, 1000, 1, null, null, null, 'Orange', null],
            ['Moov Money', 'Retrait via Moov Money', 'withdrawal', 'mobile_money', 1, 5, 1000, 1, null, null, null, 'Moov', null],
        ];
        
        foreach ($methods as $method) {
            list($name, $desc, $type, $key, $active, $min, $max, $feePct, $feeFixed, $cryptoAddr, $cryptoNet, $mobileProvider, $bankName) = $method;
            
            if (!DB::table('payment_methods')->where('name', $name)->where('type', $type)->exists()) {
                DB::table('payment_methods')->insert([
                    'name' => $name,
                    'description' => $desc,
                    'type' => $type,
                    'method_key' => $key,
                    'is_active' => $active,
                    'min_amount' => $min,
                    'max_amount' => $max,
                    'fee_percentage' => $feePct,
                    'fee_fixed' => $feeFixed,
                    'crypto_address' => $cryptoAddr,
                    'crypto_network' => $cryptoNet,
                    'mobile_money_provider' => $mobileProvider,
                    'bank_name' => $bankName,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    /**
     * Liste toutes les méthodes de paiement
     */
    public function index(Request $request)
    {
        $this->ensureTableExists();
        
        $query = PaymentMethod::query();

        // Filtrer par type si fourni
        if ($request->has('type')) {
            $query->where('type', $request->type);
        }

        // Filtrer par méthode key si fourni
        if ($request->has('method_key')) {
            $query->where('method_key', $request->method_key);
        }

        // Filtrer par statut actif si fourni
        if ($request->has('is_active')) {
            $query->where('is_active', $request->is_active);
        }

        $methods = $query->orderBy('type', 'asc')
            ->orderBy('name', 'asc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $methods
        ]);
    }

    /**
     * Récupère une méthode de paiement spécifique
     */
    public function show($id)
    {
        $this->ensureTableExists();
        
        $method = PaymentMethod::findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $method
        ]);
    }

    /**
     * Crée une nouvelle méthode de paiement
     */
    public function store(Request $request)
    {
        $this->ensureTableExists();
        
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'type' => 'required|in:deposit,withdrawal',
            'method_key' => 'required|in:crypto,cash,bank_transfer,mobile_money',
            'is_active' => 'nullable|boolean',
            'min_amount' => 'nullable|numeric|min:0',
            'max_amount' => 'nullable|numeric|min:0|gte:min_amount',
            'fee_percentage' => 'nullable|numeric|min:0|max:100',
            'fee_fixed' => 'nullable|numeric|min:0',
            // Crypto specific
            'crypto_address' => 'nullable|string|max:255',
            'crypto_network' => 'nullable|string|max:100',
            // Mobile money specific
            'mobile_money_provider' => 'nullable|string|in:MTN,Orange,Moov',
            // Bank transfer specific
            'bank_name' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $data = $request->only([
            'name',
            'description',
            'type',
            'method_key',
            'is_active',
            'min_amount',
            'max_amount',
            'fee_percentage',
            'fee_fixed',
            'crypto_address',
            'crypto_network',
            'mobile_money_provider',
            'bank_name',
        ]);

        // Set default values
        $data['is_active'] = $request->get('is_active', true);

        $method = PaymentMethod::create($data);

        return response()->json([
            'success' => true,
            'message' => 'Payment method created successfully',
            'data' => $method
        ], 201);
    }

    /**
     * Met à jour une méthode de paiement
     */
    public function update(Request $request, $id)
    {
        $this->ensureTableExists();
        
        $method = PaymentMethod::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'type' => 'sometimes|required|in:deposit,withdrawal',
            'method_key' => 'sometimes|required|in:crypto,cash,bank_transfer,mobile_money',
            'is_active' => 'nullable|boolean',
            'min_amount' => 'nullable|numeric|min:0',
            'max_amount' => 'nullable|numeric|min:0|gte:min_amount',
            'fee_percentage' => 'nullable|numeric|min:0|max:100',
            'fee_fixed' => 'nullable|numeric|min:0',
            // Crypto specific
            'crypto_address' => 'nullable|string|max:255',
            'crypto_network' => 'nullable|string|max:100',
            // Mobile money specific
            'mobile_money_provider' => 'nullable|string|in:MTN,Orange,Moov',
            // Bank transfer specific
            'bank_name' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $data = $request->only([
            'name',
            'description',
            'type',
            'method_key',
            'is_active',
            'min_amount',
            'max_amount',
            'fee_percentage',
            'fee_fixed',
            'crypto_address',
            'crypto_network',
            'mobile_money_provider',
            'bank_name',
        ]);

        $method->update($data);

        return response()->json([
            'success' => true,
            'message' => 'Payment method updated successfully',
            'data' => $method->fresh()
        ]);
    }

    /**
     * Supprime une méthode de paiement
     */
    public function destroy($id)
    {
        $this->ensureTableExists();
        
        $method = PaymentMethod::findOrFail($id);
        $method->delete();

        return response()->json([
            'success' => true,
            'message' => 'Payment method deleted successfully'
        ]);
    }
}
