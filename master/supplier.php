<?php
$entityConfig = [
    'slug' => 'supplier', 'table' => 'suppliers', 'title' => 'Master supplier', 'singular' => 'Supplier', 'plural' => 'Daftar supplier',
    'description' => 'Kelola pemasok obat, narahubung, dan informasi kontak.',
    'search_placeholder' => 'Cari nama supplier, kontak, telepon, atau email...',
    'from_sql' => 'FROM suppliers e', 'select_sql' => 'SELECT e.id, e.supplier_name, e.contact_person, e.phone, e.email, e.is_active FROM suppliers e',
    'search_columns' => ['e.supplier_name', 'e.contact_person', 'e.phone', 'e.email'], 'order_by' => 'e.supplier_name ASC',
    'fields' => [
        ['name'=>'supplier_name','label'=>'Nama supplier','type'=>'text','required'=>true,'max'=>150,'placeholder'=>'Nama perusahaan pemasok'],
        ['name'=>'contact_person','label'=>'Nama kontak','type'=>'text','max'=>120,'placeholder'=>'Nama narahubung'],
        ['name'=>'phone','label'=>'Nomor telepon','type'=>'text','max'=>30,'placeholder'=>'Nomor telepon / WhatsApp'],
        ['name'=>'email','label'=>'Email','type'=>'email','max'=>120,'placeholder'=>'nama@perusahaan.co.id'],
        ['name'=>'address','label'=>'Alamat','type'=>'textarea','max'=>65535,'wide'=>true,'placeholder'=>'Alamat lengkap supplier'],
    ],
    'columns' => [
        ['key'=>'supplier_name','label'=>'Supplier','sub'=>'contact_person'],
        ['key'=>'phone','label'=>'Telepon'], ['key'=>'email','label'=>'Email'], ['key'=>'address','label'=>'Alamat'],
    ],
];
require __DIR__ . '/_entity.php';
