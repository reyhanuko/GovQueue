<?php

namespace Database\Seeders;

use App\Models\Service;
use App\Models\ServiceWindow;
use Illuminate\Database\Seeder;

class ServiceCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $windows = [
            'WINDOW_1' => ['name' => 'Window 1 - Certificates', 'avg_service_minutes' => 10],
            'WINDOW_2' => ['name' => 'Window 2 - Permits', 'avg_service_minutes' => 15],
            'WINDOW_3' => ['name' => 'Window 3 - Clearance', 'avg_service_minutes' => 20],
        ];

        foreach ($windows as $code => $data) {
            ServiceWindow::updateOrCreate(
                ['code' => $code],
                [
                    'name' => $data['name'],
                    'description' => $data['name'].' of the Municipal Government of Tupi.',
                    'avg_service_minutes' => $data['avg_service_minutes'],
                    'is_active' => true,
                ],
            );
        }

        $catalog = [
            ['Certificate of Indigency', 'CERT-IND', 'WINDOW_1', 0, 10,
                'Certification that a resident belongs to an indigent family in the municipality.',
                "Valid government-issued ID\nBarangay certification\nHousehold composition (if requested)"],
            ['Certificate of Residency', 'CERT-RES', 'WINDOW_1', 50, 10,
                'Certification that a person resides in the Municipality of Tupi.',
                "Valid government-issued ID\nProof of residence\nBarangay verification"],
            ['Senior Citizen ID', 'SENIOR-ID', 'WINDOW_1', 0, 15,
                'issuance of a Senior Citizen ID under RA 9994.',
                "Valid government-issued ID\nBirth certificate or PSA record\n1x1 photo"],
            ['Barangay Clearance', 'BRGY-CLR', 'WINDOW_3', 100, 15,
                'Barangay-level clearance for residents of Tupi.',
                "Valid government-issued ID\nCommunity Tax Certificate\nBarangay residency proof"],
            ['Business Permit', 'BUS-PERMIT', 'WINDOW_2', 500, 20,
                'Annual business permit for businesses operating within the municipality.',
                "SEC registration or DTI record\nZonning clearance\nFire permit (if applicable)\nLease contract or title"],
            ['Building Permit', 'BLDG-PERMIT', 'WINDOW_2', 1000, 30,
                'Building and construction permit application.',
                "Application form\nSite development plan\nStructural plans signed by an architect"],
            ['Police Clearance', 'POL-CLR', 'WINDOW_3', 200, 15,
                'Police clearance certificate issued through the municipal police office.',
                "Valid government-issued ID\nCommunity Tax Certificate\n1x1 photo"],
            ['Farmers/ Fisherfolk ID', 'FARM-ID', 'WINDOW_3', 0, 20,
                'Identification for registered farmers and fisherfolk of Tupi.',
                "Valid government-issued ID\nProof of farm or fishing activity\nBarangay endorsement"],
        ];

        foreach ($catalog as [$name, $code, $windowCode, $fee, $duration, $description, $requirements]) {
            Service::updateOrCreate(
                ['code' => $code],
                [
                    'service_window_id' => ServiceWindow::where('code', $windowCode)->value('id'),
                    'name' => $name,
                    'description' => $description,
                    'requirements' => $requirements,
                    'fee' => $fee,
                    'duration_minutes' => $duration,
                    'is_active' => true,
                ],
            );
        }
    }
}
