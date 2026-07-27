import api from './axios';
import type { JenisAset } from './jenisAset';
import type { Supplier } from './supplier';
import type { KelengkapanMaster } from './kelengkapanMaster';

export type AsetStatus = 'tersedia' | 'dipakai' | 'rusak' | 'menunggu_perbaikan' | 'diperbaiki' | 'rusak_berat' | 'dijual';
export type AsetPemakaiStatus = 'pending' | 'disetujui' | 'ditolak';

export interface KaryawanUser {
  id: number;
  name: string;
  role?: string;
  pekerja?: {
    id: number;
    nip: string;
    departemen?: { id: number; nama: string } | null;
    jabatan?: { id: number; nama: string } | null;
  } | null;
}

export interface AsetKelengkapan {
  id: number;
  aset_id: number;
  kelengkapan_master_id: number;
  kelengkapan_master?: KelengkapanMaster;
  keterangan: string | null;
}

export interface AsetPemakai {
  created_at: string;
  id: number;
  aset_id: number;
  // salah satu dari dua ini yang terisi: pekerja_id buat karyawan, user_id buat akun cabang
  pekerja_id: number | null;
  pekerja?: { id: number; nip: string; user?: { id: number; name: string } };
  user_id: number | null;
  user?: { id: number; name: string } | null;
  status: AsetPemakaiStatus;
  requested_by_user_id: number | null;
  nomor_penerimaan: string | null;
  no_struk_penerimaan: string | null;
  tanggal_penerimaan: string | null; // nullable — request pending belum ada tanggal penerimaan
  catatan_penerimaan: string | null;
  nomor_pengembalian: string | null;
  no_struk_pengembalian: string | null;
  tanggal_pengembalian: string | null;
  catatan_pengembalian: string | null;
  catatan_penolakan: string | null;
  aset?: Aset; // keisi kalau di-load dari endpoint /aset-pemakai/pending
}

export interface AsetPenanganan {
  aset: any;
  id: number;
  aset_id: number;
  aset_pemakai_id: number | null;
  jenis_kerusakan: 'software' | 'hardware';
  keluhan: string;
  tanggal_lapor: string;
  tanggal_diterima: string | null;
  tanggal_selesai: string | null;
  harga_jasa: number | null;
  biaya_komponen: number | null;
  hasil: string | null;
  no_struk: string | null;
  catatan: string | null;
  // dikirim backend lewat accessor, bukan kolom asli
  total_biaya?: number;
  durasi_hari?: number | null;
  // siapa yang lagi pegang aset ini pas dilaporkan rusak (nullable — bisa juga ketauan pas audit gudang)
  // NOTE: sama seperti AsetPemakai, penerima bisa karyawan (lewat pekerja.user)
  // ATAU akun cabang (lewat user langsung) — makanya dua-duanya perlu ada di sini.
  // ⚠️ Pastikan endpoint backend yang isi field ini (aset-penanganan) juga
  // eager-load relasi `user`, bukan cuma `pekerja.user`, kalau belum, field
  // ini tetap kosong walau frontend sudah baca dari sini.
  pemakai?: {
    id: number;
    pekerja?: { id: number; user?: { id: number; name: string } };
    user?: { id: number; name: string } | null;
  } | null;
}

export interface AsetPenggantianSparepart {
  id: number;
  aset_id: number;
  tanggal: string;
  nama_sparepart: string;
  keterangan: string | null;
  biaya: number | null;
}

export interface Aset {
  id: number;
  kode_aset: string;
  jenis_id: number | null;
  jenis?: JenisAset | null;
  merek: string | null;
  tipe: string | null;
  warna: string | null;
  serial_number: string | null;
  perusahaan: string | null;
  keterangan: string | null;
  foto: string | null;
  supplier_id: number | null;
  supplier?: Supplier | null;
  tanggal_pembelian: string | null;
  no_surat_jalan: string | null;
  no_good_receive: string | null;
  status: AsetStatus;
  kelengkapan?: AsetKelengkapan[];
  pemakai_saat_ini?: AsetPemakai | null;
  pemakai?: AsetPemakai[]; // riwayat lengkap, cuma keisi di endpoint show()
  pemakai_pending?: AsetPemakai[]; // request pinjam yang masih menunggu persetujuan admin
  penanganan?: AsetPenanganan[]; // riwayat lengkap, cuma keisi di endpoint show()
  penggantian_sparepart?: AsetPenggantianSparepart[];
  penanganan_aktif?: { id: number; jenis_kerusakan: string; keluhan: string; tanggal_lapor: string } | null;
}

export interface AsetFormValues {
  jenis_id?: number | null;
  merek?: string;
  tipe?: string;
  warna?: string;
  serial_number?: string;
  perusahaan?: string;
  keterangan?: string;
  foto?: File | null;
  supplier_id?: number | null;
  tanggal_pembelian?: string;
  no_surat_jalan?: string;
  no_good_receive?: string;
  kelengkapan?: { kelengkapan_master_id: number; keterangan?: string }[];
}

function buildAsetFormData(values: AsetFormValues): FormData {
  const fd = new FormData();
  if (values.jenis_id != null) fd.append('jenis_id', String(values.jenis_id));
  if (values.merek) fd.append('merek', values.merek);
  if (values.tipe) fd.append('tipe', values.tipe);
  if (values.warna) fd.append('warna', values.warna);
  if (values.serial_number) fd.append('serial_number', values.serial_number);
  if (values.perusahaan) fd.append('perusahaan', values.perusahaan);
  if (values.keterangan) fd.append('keterangan', values.keterangan);
  if (values.foto) fd.append('foto', values.foto);
  if (values.supplier_id != null) fd.append('supplier_id', String(values.supplier_id));
  if (values.tanggal_pembelian) fd.append('tanggal_pembelian', values.tanggal_pembelian);
  if (values.no_surat_jalan) fd.append('no_surat_jalan', values.no_surat_jalan);
  if (values.no_good_receive) fd.append('no_good_receive', values.no_good_receive);
  if (values.kelengkapan) fd.append('kelengkapan', JSON.stringify(values.kelengkapan));
  return fd;
}

export async function getAset(): Promise<Aset[]> {
  const res = await api.get<Aset[]>('/aset');
  return res.data;
}

export async function getAsetById(id: number): Promise<Aset> {
  const res = await api.get<Aset>(`/aset/${id}`);
  return res.data;
}

// POST /aset (multipart) — dibatasi backend ke role admin.
export async function createAset(values: AsetFormValues): Promise<Aset> {
  const res = await api.post<Aset>('/aset', buildAsetFormData(values));
  return res.data;
}

// POST /aset/{id} + _method=PUT (multipart, krn ada file upload) — dibatasi backend ke role admin.
export async function updateAset(id: number, values: AsetFormValues): Promise<Aset> {
  const fd = buildAsetFormData(values);
  const res = await api.post<Aset>(`/aset/${id}`, fd);
  return res.data;
}

// DELETE /aset/{id} — dibatasi backend ke role admin.
export async function deleteAset(id: number): Promise<{ message: string }> {
  const res = await api.delete<{ message: string }>(`/aset/${id}`);
  return res.data;
}

/**
 * Cari karyawan atau akun cabang (buat dipilih sebagai pemakai aset). Pakai
 * endpoint /karyawan yang sudah ada (UserController::index), yang eager-load
 * relasi pekerja dan sudah support filter ?role=.
 *
 * Kirim role='cabang' buat nampilin akun cabang aja. Kosongkan (undefined)
 * buat perilaku lama (semua role, biasanya dipakai buat cari karyawan).
 */
export async function searchKaryawan(query: string, role?: string): Promise<KaryawanUser[]> {
  const res = await api.get<KaryawanUser[]>('/karyawan', {
    params: { search: query, ...(role ? { role } : {}) },
  });
  return res.data;
}

// POST /aset/{aset}/pemakai — serah-terima aset ke pekerja ATAU akun cabang.
// Kirim salah satu: pekerja_id (karyawan) atau user_id (cabang), jangan dua-duanya.
// Dibatasi backend ke role admin.
export async function serahTerimaAset(
  asetId: number,
  payload: {
    pekerja_id?: number;
    user_id?: number;
    nomor_penerimaan?: string;
    tanggal_penerimaan: string;
    catatan_penerimaan?: string;
  }
): Promise<AsetPemakai> {
  const res = await api.post<AsetPemakai>(`/aset/${asetId}/pemakai`, payload);
  return res.data;
}

// POST /aset-pemakai/{id}/kembalikan — dibatasi backend ke role admin. Wajib
// sertain no_struk_penerimaan (struk asli pas serah-terima) buat validasi backend.
export async function kembalikanAset(
  asetPemakaiId: number,
  payload: {
    no_struk_penerimaan: string;
    nomor_pengembalian?: string;
    tanggal_pengembalian: string;
    catatan_pengembalian?: string;
  }
): Promise<AsetPemakai> {
  const res = await api.post<AsetPemakai>(`/aset-pemakai/${asetPemakaiId}/kembalikan`, payload);
  return res.data;
}

// POST /aset/{aset}/pinjam — karyawan request pinjam aset (status 'tersedia' only).
// Aset TIDAK langsung pindah status; nunggu admin approve lewat setujuiAsetPemakai().
export async function requestPinjamAset(
  asetId: number,
  payload: { catatan_penerimaan?: string }
): Promise<AsetPemakai> {
  const res = await api.post<AsetPemakai>(`/aset/${asetId}/pinjam`, payload);
  return res.data;
}

// GET /aset-pemakai/pending — daftar semua request pinjam yang belum diproses. Dibatasi backend ke role admin.
export async function getPendingAsetPemakai(): Promise<AsetPemakai[]> {
  const res = await api.get<AsetPemakai[]>('/aset-pemakai/pending');
  return res.data;
}

// Satu entri riwayat aktivitas aset — bisa dari peminjaman (pinjam/kembali)
// atau penanganan kerusakan (lapor_rusak/selesai_perbaikan), digabung backend.
export interface RiwayatAsetEvent {
  type: 'pinjam' | 'kembali' | 'lapor_rusak' | 'selesai_perbaikan';
  waktu: string;
  nama: string | null;
  aset: { id: number; kode_aset: string; merek: string | null; tipe: string | null } | null;
  keluhan?: string | null;
  hasil?: string | null;
}

// GET /aset-pemakai/riwayat — riwayat SEMUA aktivitas aset (pinjam, kembali,
// lapor rusak, selesai perbaikan), terbaru duluan. Dibatasi backend ke role
// admin. Dipakai panel Riwayat di tab Aset (BUKAN riwayat peminjaman barang).
export async function getRiwayatAset(limit = 10): Promise<RiwayatAsetEvent[]> {
  const res = await api.get<RiwayatAsetEvent[]>('/aset-pemakai/riwayat', { params: { limit } });
  return res.data;
}

// POST /aset-pemakai/{id}/setujui — approve request pinjam, aset jadi 'dipakai'. Dibatasi backend ke role admin.
export async function setujuiAsetPemakai(
  asetPemakaiId: number,
  payload?: { nomor_penerimaan?: string; tanggal_penerimaan?: string }
): Promise<AsetPemakai> {
  const res = await api.post<AsetPemakai>(`/aset-pemakai/${asetPemakaiId}/setujui`, payload || {});
  return res.data;
}

// POST /aset-pemakai/{id}/tolak — reject request pinjam, aset tetap 'tersedia'. Dibatasi backend ke role admin.
export async function tolakAsetPemakai(
  asetPemakaiId: number,
  payload?: { catatan_penolakan?: string }
): Promise<AsetPemakai> {
  const res = await api.post<AsetPemakai>(`/aset-pemakai/${asetPemakaiId}/tolak`, payload || {});
  return res.data;
}

// POST /aset-penanganan — lapor kerusakan aset. aset_id wajib dikirim di payload
// (endpoint ini gak nempel di path /aset/{aset}, beda dari pola lain di file ini).
export async function laporPenangananAset(payload: {
  aset_id: number;
  jenis_kerusakan: 'software' | 'hardware';
  keluhan: string;
}): Promise<AsetPenanganan> {
  const res = await api.post<AsetPenanganan>('/aset-penanganan', payload);
  return res.data;
}

// POST /aset-penanganan/{id}/terima — admin terima/mulai tangani laporan kerusakan,
// aset jadi status "diperbaiki". Dibatasi backend ke role admin.
export async function terimaPenangananAset(asetPenangananId: number): Promise<AsetPenanganan> {
  const res = await api.post<AsetPenanganan>(`/aset-penanganan/${asetPenangananId}/terima`);
  return res.data;
}

// POST /aset-penanganan/{id} — admin tandai selesai + isi hasil/biaya.
// no_struk digenerate otomatis backend, gak perlu dikirim dari sini.
export async function selesaikanPenangananAset(
  asetPenangananId: number,
  payload: Partial<{
    tanggal_selesai: string | null;
    harga_jasa: number | null;
    biaya_komponen: number | null;
    hasil: string | null;
    catatan: string | null;
  }>
): Promise<AsetPenanganan> {
  const res = await api.post<AsetPenanganan>(`/aset-penanganan/${asetPenangananId}`, payload);
  return res.data;
}

// DELETE /aset-penanganan/{id} — dibatasi backend ke role admin.
export async function deletePenangananAset(asetPenangananId: number): Promise<{ message: string }> {
  const res = await api.delete<{ message: string }>(`/aset-penanganan/${asetPenangananId}`);
  return res.data;
}

// POST /aset/{aset}/penggantian-sparepart — dibatasi backend ke role admin.
export async function tambahPenggantianSparepart(
  asetId: number,
  payload: {
    tanggal: string;
    nama_sparepart: string;
    keterangan?: string;
    biaya?: number;
  }
): Promise<AsetPenggantianSparepart> {
  const res = await api.post<AsetPenggantianSparepart>(`/aset/${asetId}/penggantian-sparepart`, payload);
  return res.data;
}

// DELETE /aset-penggantian-sparepart/{id} — dibatasi backend ke role admin.
export async function deletePenggantianSparepart(id: number): Promise<{ message: string }> {
  const res = await api.delete<{ message: string }>(`/aset-penggantian-sparepart/${id}`);
  return res.data;
}

// BARU: POST /aset/{aset}/jual — tandai aset (status 'tersedia' atau
// 'rusak_berat') sebagai terjual. Aset pindah status jadi 'dijual'. Cuma
// tanda status, gak ada input tambahan (harga/catatan) dari frontend.
// Dibatasi backend ke role admin.
export async function jualAset(asetId: number): Promise<Aset> {
  const res = await api.post<Aset>(`/aset/${asetId}/jual`);
  return res.data;
}