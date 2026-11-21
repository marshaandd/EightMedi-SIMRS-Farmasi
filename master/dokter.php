<?php
$entityConfig = [
    'slug' => 'dokter', 'table' => 'doctors', 'title' => 'Master dokter', 'singular' => 'Dokter', 'plural' => 'Daftar dokter',
    'description' => 'Kelola nama dokter dan poliklinik tempat dokter memberikan pelayanan.',
    'search_placeholder' => 'Cari nama dokter atau poliklinik...',
    'from_sql' => 'FROM doctors e LEFT JOIN polyclinics p ON p.id = e.polyclinic_id',
    'select_sql' => 'SELECT e.id, e.doctor_name, e.polyclinic_id, p.polyclinic_name, e.is_active FROM doctors e LEFT JOIN polyclinics p ON p.id = e.polyclinic_id',
    'search_columns' => ['e.doctor_name', 'p.polyclinic_name'], 'order_by' => 'e.doctor_name ASC',
    'fields' => [
        ['name'=>'doctor_name','label'=>'Nama dokter','type'=>'text','required'=>true,'max'=>150,'placeholder'=>'Nama lengkap dokter'],
        ['name'=>'polyclinic_id','label'=>'Poliklinik','type'=>'select','max'=>20,'empty_label'=>'Belum ditentukan','option_label'=>'polyclinic_name','options_sql'=>'SELECT id, polyclinic_name, is_active FROM polyclinics ORDER BY is_active DESC, polyclinic_name ASC','help'=>'Pilih poliklinik jika sudah diketahui.'],
    ],
    'columns' => [ ['key'=>'doctor_name','label'=>'Dokter'], ['key'=>'polyclinic_name','label'=>'Poliklinik'] ],
];
require __DIR__ . '/_entity.php';
