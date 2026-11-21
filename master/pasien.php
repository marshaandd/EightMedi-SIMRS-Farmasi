<?php
$entityConfig = [
    'slug' => 'pasien',
    'table' => 'patients',
    'title' => 'Master pasien',
    'singular' => 'Pasien',
    'plural' => 'Daftar pasien',
    'description' => 'Kelola identitas pasien sebagai referensi kunjungan dan pelayanan resep.',
    'search_placeholder' => 'Cari nomor rekam medis, NIK, nama, atau telepon...',
    'from_sql' => 'FROM patients e',
    'select_sql' => "SELECT e.id, e.medical_record_no, e.full_name, e.gender,
        CASE e.gender WHEN 'L' THEN 'Laki-laki' WHEN 'P' THEN 'Perempuan' ELSE 'Belum diisi' END AS gender_label,
        e.birth_date, e.phone, e.is_active
        FROM patients e",
    'search_columns' => ['e.medical_record_no', 'e.nik', 'e.full_name', 'e.phone'],
    'order_by' => 'e.full_name ASC',
    'auto_created_by' => true,
    'fields' => [
        ['name'=>'medical_record_no','label'=>'Nomor rekam medis','type'=>'text','required'=>true,'max'=>40,'placeholder'=>'Contoh: RM-2026-0001','help'=>'Harus unik untuk setiap pasien.'],
        ['name'=>'nik','label'=>'NIK','type'=>'text','max'=>30,'placeholder'=>'16 digit NIK (opsional)','help'=>'Boleh dikosongkan jika belum tersedia.'],
        ['name'=>'full_name','label'=>'Nama lengkap','type'=>'text','required'=>true,'max'=>150,'placeholder'=>'Nama lengkap pasien'],
        ['name'=>'gender','label'=>'Jenis kelamin','type'=>'select','required'=>true,'default'=>'UNKNOWN','options_sql'=>"SELECT 'L' AS id, 'Laki-laki' AS name UNION ALL SELECT 'P', 'Perempuan' UNION ALL SELECT 'UNKNOWN', 'Belum diisi'",'option_label'=>'name','empty_label'=>'Pilih jenis kelamin'],
        ['name'=>'birth_date','label'=>'Tanggal lahir','type'=>'date','placeholder'=>''],
        ['name'=>'phone','label'=>'Nomor telepon','type'=>'text','max'=>30,'placeholder'=>'Nomor telepon pasien'],
        ['name'=>'address','label'=>'Alamat','type'=>'textarea','max'=>4000,'wide'=>true,'placeholder'=>'Alamat lengkap pasien (opsional)'],
    ],
    'columns' => [
        ['key'=>'full_name','label'=>'Pasien','sub'=>'medical_record_no'],
        ['key'=>'gender_label','label'=>'Jenis kelamin'],
        ['key'=>'birth_date','label'=>'Tanggal lahir'],
        ['key'=>'phone','label'=>'Telepon'],
    ],
];
require __DIR__ . '/_entity.php';
