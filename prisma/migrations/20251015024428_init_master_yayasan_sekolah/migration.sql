-- CreateTable
CREATE TABLE "Yayasan" (
    "id" TEXT NOT NULL,
    "name" TEXT NOT NULL,
    "createdAt" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updatedAt" TIMESTAMP(3) NOT NULL,

    CONSTRAINT "Yayasan_pkey" PRIMARY KEY ("id")
);

-- CreateTable
CREATE TABLE "Sekolah" (
    "id" TEXT NOT NULL,
    "yayasanId" TEXT NOT NULL,
    "name" TEXT NOT NULL,
    "jenjang" TEXT,
    "kecamatan" TEXT,
    "kabupaten" TEXT,
    "provinsi" TEXT,
    "npsn" TEXT,
    "createdAt" TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updatedAt" TIMESTAMP(3) NOT NULL,

    CONSTRAINT "Sekolah_pkey" PRIMARY KEY ("id")
);

-- CreateIndex
CREATE UNIQUE INDEX "Sekolah_npsn_key" ON "Sekolah"("npsn");

-- CreateIndex
CREATE INDEX "idx_sekolah_yayasan" ON "Sekolah"("yayasanId");

-- CreateIndex
CREATE INDEX "idx_sekolah_name" ON "Sekolah"("name");

-- AddForeignKey
ALTER TABLE "Sekolah" ADD CONSTRAINT "Sekolah_yayasanId_fkey" FOREIGN KEY ("yayasanId") REFERENCES "Yayasan"("id") ON DELETE CASCADE ON UPDATE CASCADE;
