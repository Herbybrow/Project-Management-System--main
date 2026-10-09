import { Modal, Spin, Button, message } from 'antd'
import { useCallback, useEffect, useState } from 'react'
import dayjs from 'dayjs'
import ReviewStatusBadge from '../common/ReviewStatusBadge'
import DocumentUploadModal from '../documents/DocumentUploadModal'
import DataTable from '../common/DataTable'
import api from '../../lib/axios'
import { unwrapList } from '../../lib/apiHelpers'
import { MODAL_WIDTH } from '../../lib/modalSizes'

function ActivityDocumentsModal({ open, activity, projectId, onCancel }) {
  const [documents, setDocuments] = useState([])
  const [uploadOpen, setUploadOpen] = useState(false)
  const [loading, setLoading] = useState(false)
  const activityId = activity?.id

  const load = useCallback(async () => {
    if (!open || !projectId || !activityId) return
    setLoading(true)
    try {
      const response = await api.get(`/projects/${projectId}/documents`, {
        params: { activity_id: activityId },
      })
      setDocuments(unwrapList(response.data))
    } catch (err) {
      message.error(err.response?.data?.message || 'Could not load documents')
    } finally {
      setLoading(false)
    }
  }, [open, projectId, activityId])

  useEffect(() => {
    load()
  }, [load])

  return (
    <>
      <Modal
        title={`Documents — ${activity?.name || ''}`}
        open={open}
        onCancel={onCancel}
        footer={null}
        width={MODAL_WIDTH.xl}
        className="pms-modal-xl"
      >
        <div className="mb-3 flex justify-end">
          <Button type="primary" onClick={() => setUploadOpen(true)}>
            Upload document
          </Button>
        </div>
        <Spin spinning={loading}>
          <DataTable
            rowKey="id"
            data={documents}
            searchPlaceholder="Search documents..."
            columns={[
              { title: 'File', dataIndex: 'file_name' },
              { title: 'Document Type', dataIndex: 'document_type', width: 160 },
              {
                title: 'Review Status',
                dataIndex: 'review_status',
                width: 130,
                render: (value) => <ReviewStatusBadge status={value} />,
              },
              {
                title: 'Uploaded At',
                dataIndex: 'uploaded_at',
                width: 130,
                render: (value) => (value ? dayjs(value).format('MMM D, YYYY') : '—'),
              },
            ]}
          />
        </Spin>
      </Modal>
      <DocumentUploadModal
        open={uploadOpen}
        projectId={projectId}
        activityId={activity?.id}
        onCancel={() => setUploadOpen(false)}
        onUploaded={() => {
          setUploadOpen(false)
          load()
        }}
      />
    </>
  )
}

export default ActivityDocumentsModal
