import { apiClient } from '@/services/apiClient'
import type { ImportBatch, ImportUploadResponse } from '@/types/api'

export async function uploadLetterboxdExport(file: File): Promise<ImportUploadResponse> {
  const formData = new FormData()
  formData.append('file', file)

  const { data } = await apiClient.post<ImportUploadResponse>('/import/letterboxd', formData, {
    headers: { 'Content-Type': 'multipart/form-data' },
  })
  return data
}

export async function fetchImportBatch(id: string): Promise<ImportBatch> {
  const { data } = await apiClient.get<ImportBatch>(`/import/${id}`)
  return data
}

export async function fetchImportBatches(): Promise<ImportBatch[]> {
  const { data } = await apiClient.get<ImportBatch[]>('/import')
  return data
}
