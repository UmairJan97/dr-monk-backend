<?php

namespace Database\Seeders;

use App\Models\BillingCode;
use App\Models\Claim;
use App\Models\Clinic;
use App\Models\Expense;
use App\Models\Patient;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Database\Seeder;

class BillingDemoSeeder extends Seeder
{
    public function run(): void
    {
        $clinic = Clinic::query()->where('slug', 'demo-clinic')->first();
        $billing = User::query()->where('email', 'billing@demo.local')->first();
        $frontDesk = User::query()->where('email', 'desk@demo.local')->first();

        if (! $clinic || ! $billing) {
            $this->command?->warn('Demo clinic/billing user missing.');

            return;
        }

        $patients = Patient::query()
            ->where('clinic_id', $clinic->id)
            ->orderBy('id')
            ->limit(12)
            ->get();

        if ($patients->count() < 10) {
            $this->command?->warn('Need FrontDeskDemoSeeder patients first.');

            return;
        }

        Claim::query()
            ->where('clinic_id', $clinic->id)
            ->where('clearinghouse_id', 'like', 'DEMO-BILL-%')
            ->delete();

        Expense::query()
            ->where('clinic_id', $clinic->id)
            ->where('description', 'like', '%[demo-billing]%')
            ->delete();

        Payment::query()
            ->where('clinic_id', $clinic->id)
            ->where('receipt_number', 'like', 'DEMO-BILL-%')
            ->delete();

        BillingCode::query()
            ->where('clinic_id', $clinic->id)
            ->where('description', 'like', '%[demo-billing]%')
            ->delete();

        $statuses = [
            'draft', 'draft', 'submitted', 'submitted', 'accepted',
            'accepted', 'denied', 'submitted', 'draft', 'accepted',
            'denied', 'submitted',
        ];
        $amounts = [125.00, 180.50, 95.00, 210.00, 150.00, 88.00, 175.00, 240.00, 110.00, 160.00, 132.00, 199.00];

        foreach ($patients->take(12)->values() as $i => $patient) {
            $status = $statuses[$i] ?? 'draft';
            $billed = $amounts[$i] ?? 100;
            $paid = in_array($status, ['accepted'], true) ? round($billed * 0.8, 2) : 0;

            Claim::query()->create([
                'clinic_id' => $clinic->id,
                'patient_id' => $patient->id,
                'created_by' => $billing->id,
                'status' => $status,
                'clearinghouse_id' => 'DEMO-BILL-'.($i + 1),
                'x12_payload' => $status === 'draft' ? null : 'ISA*00*...DEMO...',
                'denial_codes' => $status === 'denied' ? ['CO-4', 'PR-1'] : null,
                'billed_amount' => $billed,
                'paid_amount' => $paid,
            ]);

            BillingCode::query()->create([
                'clinic_id' => $clinic->id,
                'patient_id' => $patient->id,
                'code_system' => $i % 2 === 0 ? 'CPT' : 'ICD10',
                'code' => $i % 2 === 0 ? ($i % 4 === 0 ? '99213' : '99214') : 'J06.9',
                'description' => ($i % 2 === 0 ? 'Office visit' : 'URI') . ' [demo-billing]',
                'source' => 'ai_suggest',
                'status' => $i < 10 ? 'suggested' : 'confirmed',
                'confirmed_by' => $i < 10 ? null : $billing->id,
            ]);
        }

        $expenseCats = [
            ['supplies', 42.50, 'Exam gloves box [demo-billing]'],
            ['rent', 2200.00, 'Suite rent MTD [demo-billing]'],
            ['utilities', 310.00, 'Electricity [demo-billing]'],
            ['payroll', 4800.00, 'Front desk wages [demo-billing]'],
            ['software', 199.00, 'EMR SaaS seat [demo-billing]'],
            ['supplies', 65.00, 'Alcohol swabs [demo-billing]'],
            ['marketing', 120.00, 'Local ads [demo-billing]'],
            ['maintenance', 85.00, 'HVAC filter [demo-billing]'],
            ['insurance', 450.00, 'Malpractice premium share [demo-billing]'],
            ['misc', 35.75, 'Postage [demo-billing]'],
            ['supplies', 28.00, 'Thermometer covers [demo-billing]'],
            ['utilities', 95.00, 'Internet [demo-billing]'],
        ];

        foreach ($expenseCats as $row) {
            Expense::query()->create([
                'clinic_id' => $clinic->id,
                'recorded_by' => $billing->id,
                'category' => $row[0],
                'amount' => $row[1],
                'description' => $row[2],
                'incurred_on' => now()->subDays(rand(0, 20))->toDateString(),
            ]);
        }

        $recorder = $frontDesk?->id ?? $billing->id;
        foreach ($patients->take(10)->values() as $i => $patient) {
            Payment::query()->create([
                'clinic_id' => $clinic->id,
                'patient_id' => $patient->id,
                'recorded_by' => $recorder,
                'method' => ['cash', 'card', 'online', 'card'][$i % 4],
                'amount' => [40, 25, 60, 15, 80, 35, 50, 20, 45, 30][$i],
                'receipt_number' => 'DEMO-BILL-RCP-'.($i + 1),
                'status' => 'completed',
            ]);
        }

        $this->command?->info('Billing demo: 12 claims, 12 codes, 12 expenses, 10 payments seeded.');
    }
}
