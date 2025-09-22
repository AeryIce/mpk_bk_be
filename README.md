# 📚 Buku Kenangan Backend (mpk_bk_be)

✨ Backend resmi untuk **Proyek Buku Kenangan 50 Tahun MPK KAJ**.  
Dibangun dengan **Laravel 12**, terhubung ke **PostgreSQL (Railway)**, dengan modular architecture untuk sponsorship, kurasi, gudang, distribusi, dan tracking QR.

---

## 🚀 Tech Stack
- ⚡ **Laravel 12** (Modular Architecture)  
- 🐘 **PostgreSQL** (Railway Cloud DB)  
- 📦 **Redis Queue** (Job & Notifikasi)  
- ☁️ **Supabase Storage** (Upload Banner Sponsorship)  
- 📧 **Postmark/SMTP** (Magic Link & Email Notification)  

---

## 🗂️ Struktur Repo
mpk_bk_be/
├── app/Modules/
│ ├── Auth
│ ├── Sponsorship
│ ├── Curation
│ ├── Warehouse
│ ├── Logistics
│ ├── Scan
│ ├── Notification
│ └── Report
├── routes/
├── config/
├── database/
└── ...

---

## 🎯 Fitur Utama (MVP)
1. ✉️ **Signup Magic Link** + Set Password  
2. 📊 Dashboard Sponsor (PIC, Upload, Status)  
3. 🛠️ Admin Kurasi (List, Preview, Status, Unduh)  
4. 📦 Gudang: Buat Paket + Cetak Label QR  
5. 📱 PWA Scanner (Dispatch & Receive)  
6. 🛡️ Admin Override + Audit Log  

---

## 🖼️ Branding
- 🎨 Tema: Oranye `#F97316` + Emas `#CDA434`  
- 📌 Badge: *Buku Kenangan 50 Tahun*  
- 📱 Mobile-first Dashboard & PWA  

---

🤝 Tim & Kontribusi

Proyek kolaborasi tim panitia MPK KAJ.
Pull Request & Issue akan dipantau untuk pengembangan berkelanjutan.

📌 Lisensi
© 2025 MPK KAJ — All Rights Reserved.