<?php
// Bhavi Creations payment details shown to clients on the Payments page ("How to pay")
// and printed on invoices. Fill these in. Leave a value as '' to hide that row.
return [
    'upi_id'       => '',   // e.g. 'bhavicreations@okaxis' (makes the "Scan to Pay" QR on invoices)
    'bank_name'    => 'HDFC Bank, Kakinada',
    'account_no'   => '59213749999999',
    'ifsc'         => 'HDFC0000426',
    'account_name' => 'Bhavi Creations Private Limited',
    // Shown at the top of every invoice ("From").
    'company_name'    => 'Bhavi Creations Pvt Ltd',
    'company_address' => "Plot no28, H No70, 17-28, RTO Office Rd,\nRangaRaoNagar, Kakinada, AndhraPradesh 533003.",
    'company_phone'   => '9642343434',
    'company_email'   => 'admin@bhavicreations.com',
    'company_gstin'   => '37AAKCB6960H1ZB',
    'invoice_prefix'  => 'BHAVI_KKD', // invoice numbers look like BHAVI_KKD_2026_1
    // Optional images in assets/img/: a ready-made payment QR and the signature.
    'logo_image'      => 'logo-invoice.png',
    'qr_image'        => 'qrcode.jpg',
    'signature_image' => 'signature.jpeg',
];
