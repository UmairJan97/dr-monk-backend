<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Vital extends Model
{
    protected $fillable = [
        'clinic_id', 'patient_id', 'appointment_id', 'recorded_by',
        'height_cm', 'weight_kg', 'bmi', 'temperature_c', 'bp_systolic',
        'bp_diastolic', 'pulse', 'respiratory_rate', 'spo2', 'pain_scale',
        'glucose', 'notes', 'alerts',
    ];

    protected function casts(): array
    {
        return [
            'alerts' => 'array',
            'height_cm' => 'float',
            'weight_kg' => 'float',
            'bmi' => 'float',
            'temperature_c' => 'float',
            'glucose' => 'float',
        ];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public static function calculateBmi(?float $heightCm, ?float $weightKg): ?float
    {
        if (! $heightCm || ! $weightKg || $heightCm <= 0) {
            return null;
        }

        $meters = $heightCm / 100;

        return round($weightKg / ($meters * $meters), 2);
    }

    public static function detectAlerts(array $data): array
    {
        $alerts = [];

        if (($data['bp_systolic'] ?? null) && ($data['bp_systolic'] >= 140 || $data['bp_systolic'] <= 90)) {
            $alerts[] = 'blood_pressure';
        }
        if (($data['bp_diastolic'] ?? null) && ($data['bp_diastolic'] >= 90 || $data['bp_diastolic'] <= 50)) {
            $alerts[] = 'blood_pressure_diastolic';
        }
        if (($data['temperature_c'] ?? null) && $data['temperature_c'] >= 38.0) {
            $alerts[] = 'high_temperature';
        }
        if (($data['temperature_c'] ?? null) && $data['temperature_c'] <= 35.0) {
            $alerts[] = 'low_temperature';
        }
        if (($data['spo2'] ?? null) && $data['spo2'] < 92) {
            $alerts[] = 'low_oxygen';
        }
        if (($data['pulse'] ?? null) && ($data['pulse'] >= 120 || $data['pulse'] <= 50)) {
            $alerts[] = 'pulse';
        }
        if (($data['respiratory_rate'] ?? null) && ($data['respiratory_rate'] >= 24 || $data['respiratory_rate'] <= 10)) {
            $alerts[] = 'respiratory_rate';
        }
        if (($data['glucose'] ?? null) && ($data['glucose'] >= 200 || $data['glucose'] <= 60)) {
            $alerts[] = 'glucose';
        }
        if (($data['pain_scale'] ?? null) && $data['pain_scale'] >= 7) {
            $alerts[] = 'high_pain';
        }
        if (($data['bmi'] ?? null) && ($data['bmi'] >= 30 || $data['bmi'] < 18.5)) {
            $alerts[] = 'bmi';
        }

        return array_values(array_unique($alerts));
    }

    /** @param  list<string>  $codes */
    public static function alertLabels(array $codes): array
    {
        $map = [
            'blood_pressure' => 'Systolic BP out of range',
            'blood_pressure_diastolic' => 'Diastolic BP out of range',
            'high_temperature' => 'Fever (≥100.4°F / 38°C)',
            'low_temperature' => 'Hypothermia risk (≤95°F / 35°C)',
            'low_oxygen' => 'Low SpO₂ (<92%)',
            'pulse' => 'Heart rate out of range',
            'respiratory_rate' => 'Respiratory rate out of range',
            'glucose' => 'Glucose out of range (mg/dL)',
            'high_pain' => 'High pain score (≥7)',
            'bmi' => 'BMI outside healthy range',
        ];

        return array_values(array_filter(array_map(fn ($c) => $map[$c] ?? $c, $codes)));
    }
}
