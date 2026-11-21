<?php
$entityConfig = [
    'slug' => 'poliklinik', 'table' => 'polyclinics', 'title' => 'Master poliklinik', 'singular' => 'Poliklinik', 'plural' => 'Daftar poliklinik',
    'description' => 'Kelola kode dan nama poliklinik yang menjadi sumber data pelayanan.',
    'search_placeholder' => 'Cari kode atau nama poliklinik...',
    'from_sql' => 'FROM polyclinics e', 'select_sql' => 'SELECT e.id, e.polyclinic_code, e.polyclinic_name, e.is_active FROM polyclinics e',
    'search_columns' => ['e.polyclinic_code', 'e.polyclinic_name'], 'order_by' => 'e.polyclinic_name ASC',
    'fields' => [
        ['name'=>'polyclinic_code','label'=>'Kode poliklinik','type'=>'text','max'=>30,'placeholder'=>'Contoh: POLI-UMU','help'=>'Boleh dikosongkan, jika diisi harus unik.'],
        ['name'=>'polyclinic_name','label'=>'Nama poliklinik','type'=>'text','required'=>true,'max'=>120,'placeholder'=>'Nama poliklinik'],
    ],
    'columns' => [ ['key'=>'polyclinic_name','label'=>'Poliklinik','sub'=>'polyclinic_code'] ],
];
require __DIR__ . '/_entity.php';
