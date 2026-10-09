import { useCallback, useEffect, useState } from 'react'
import { useParams } from 'react-router-dom'
import { Button, Descriptions, Modal, Space, message } from 'antd'
import { UploadOutlined, DownloadOutlined, EyeOutlined } from '@ant-design/icons'
import dayjs from 'dayjs'
import DataTable from '../common/DataTable'
import ReviewStatusBadge from '../common/ReviewStatusBadge'
import DocumentUploadModal from './DocumentUploadModal'
import ProjectPicker from '../common/ProjectPicker'
import { isSpecReadOnlyRole, useActiveRoleName } from '../common/RoleGuard'
import { fetchProjectsCached } from '../../lib/projectsCache'
import { fetchCached } from '../../lib/cachedApi'
import { useRequestCacheRevision } from '../../lib/useRequestCacheRevision'
import {
  fetchAuthorizedFileUrl,
  getStoredProjectId,
  storeProjectId,
  unwrapList,
} from '../../lib/apiHelpers'
import { FileText } from 'lucide-react'
import { MODAL_WIDTH } from '../../lib/modalSizes'

const DOCUMENT_ACCENT = '#962c30'

function FileTextIcon() {
  return <FileText size={15} className="shrink-0 text-[#7b1e1e]" aria-hidden="true" />
}

function DocumentList({
  embedded = false,
  projectId: projectIdProp = null,
  compact = false,
  detailStyle = false,
  active = true,
} = {}) {
  const { id: routeId } = useParams()
  const readOnly = isSpecReadOnlyRole(useActiveRoleName())
  const [projects, setProjects] = useState([])
  const [projectId, setProjectId] = useState(() => {
    if (projectIdProp) {
      storeProjectId(projectIdProp)
      return projectIdProp
    }
    const fromRoute = Number(routeId)
    if (Number.isFinite(fromRoute) && fromRoute > 0) {
      storeProjectId(fromRoute)
      return fromRoute
    }
    return getStoredProjectId()
  })
  const [documents, setDocuments] = useState([])
  const [uploadOpen, setUploadOpen] = useState(false)
  const [viewTarget, setViewTarget] = useState(null)
  const revision = useRequestCacheRevision()

  const loadProjects = useCallback(async () => {
    const fromRoute = Number(routeId)
    const embeddedId = projectIdProp || (embedded && Number.isFinite(fromRoute) && fromRoute > 0 ? fromRoute : null)
    if (embedded && embeddedId) {
      storeProjectId(embeddedId)
      setProjectId(embeddedId)
      return
    }

    const response = await fetchProjectsCached()
    const list = unwrapList(response.data)
    setProjects(list)
    if (Number.isFinite(fromRoute) && fromRoute > 0) {
      storeProjectId(fromRoute)
      setProjectId(fromRoute)
      return
    }
    setProjectId((current) => {
      if (current && list.some((project) => project.id === current)) return current
      const first = list[0]?.id ?? null
      storeProjectId(first)
      return first
    })
  }, [routeId, embedded, projectIdProp])

  useEffect(() => {
    if (!projectIdProp) return
    storeProjectId(projectIdProp)
    setProjectId((current) => (current === projectIdProp ? current : projectIdProp))
  }, [projectIdProp])

  const loadDocuments = useCallback(async (id) => {
    if (!id) {
      setDocuments([])
      return
    }
    const response = await fetchCached(`/projects/${id}/documents`)
    setDocuments(unwrapList(response.data))
  }, [])

  useEffect(() => {
    if (active) loadProjects().catch((err) => message.error(err.response?.data?.message || 'Could not load projects'))
  }, [active, loadProjects])

  useEffect(() => {
    if (active) loadDocuments(projectId).catch((err) => message.error(err.response?.data?.message || 'Could not load documents'))
  }, [active, projectId, loadDocuments, revision])

  const downloadFile = async (doc) => {
    try {
      const url = await fetchAuthorizedFileUrl(doc.id)
      const link = document.createElement('a')
      link.href = url
      link.download = doc.file_name
      link.click()
    } catch {
      message.error('Could not download file')
    }
  }

  const closeView = () => {
    setViewTarget(null)
  }

  const columns = detailStyle
    ? [
        {
          title: 'File Name',
          dataIndex: 'file_name',
          key: 'file_name',
          ellipsis: true,
          render: (value) => (
            <span className="inline-flex items-center gap-2 font-semibold text-[#1f2937]">
              <FileTextIcon />
              {value}
            </span>
          ),
        },
        {
          title: 'Type',
          key: 'ext',
          width: 100,
          render: (_, record) => {
            const name = String(record.file_name || '')
            const ext = name.includes('.') ? name.split('.').pop().toUpperCase() : 'FILE'
            return <span className="rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-semibold text-gray-600">{ext}</span>
          },
        },
        {
          title: 'Uploaded',
          dataIndex: 'uploaded_at',
          width: 120,
          render: (value) => (value ? dayjs(value).format('MMM DD, YYYY') : '—'),
        },
        {
          title: 'Action',
          key: 'actions',
          width: 90,
          render: (_, record) => (
            <button
              type="button"
              className="border-0 bg-transparent p-0 text-sm font-bold text-[#7b1e1e] hover:underline"
              onClick={() => setViewTarget(record)}
            >
              View
            </button>
          ),
        },
      ]
    : [
    { title: 'File', dataIndex: 'file_name', key: 'file_name', width: 220 },
    { title: 'Document Type', dataIndex: 'document_type', key: 'document_type', width: 140 },
    {
      title: 'Activity Name',
      key: 'activity',
      width: 160,
      render: (_, record) =>
        record.activity?.name || (record.requirement ? `Requirement ${record.requirement.requirement_code}` : 'Project-level'),
      searchValue: (record) =>
        record.activity?.name || (record.requirement ? `Requirement ${record.requirement.requirement_code}` : 'Project-level'),
    },
    ...(compact
      ? []
      : [
          {
            title: 'Version',
            dataIndex: 'version_number',
            width: 90,
            render: (value) => `v${value}`,
          },
          {
            title: 'Review Status',
            dataIndex: 'review_status',
            width: 140,
            render: (value) => <ReviewStatusBadge status={value} />,
          },
        ]),
    ...(compact
      ? []
      : [
          {
            title: 'Reviewer Comment',
            dataIndex: 'review_comment',
            render: (value) => value || '—',
          },
        ]),
    {
      title: 'Uploaded By',
      key: 'uploaded_by',
      width: 160,
      render: (_, record) => record.uploader?.name || '—',
      searchValue: (record) => record.uploader?.name || '',
    },
    {
      title: 'Uploaded At',
      dataIndex: 'uploaded_at',
      width: 105,
      render: (value) => (value ? dayjs(value).format('MMM D, YYYY') : '—'),
    },
    {
      title: 'Action',
      key: 'actions',
      width: 155,
      onHeaderCell: () => ({ style: { whiteSpace: 'nowrap' } }),
      render: (_, record) => (
        <Button
          type="primary"
          icon={<EyeOutlined />}
          style={{ background: DOCUMENT_ACCENT, borderColor: DOCUMENT_ACCENT }}
          onClick={() => setViewTarget(record)}
        >
          View
        </Button>
      ),
    },
  ]

  return (
    <div className="page-container">
      {!embedded && (
        <h2 className="mb-4 text-lg font-semibold text-gray-800 dark:text-gray-100">Project Documents</h2>
      )}

      {(!embedded || (!readOnly && !compact)) && (
        <div className="flex flex-wrap items-center justify-end gap-3">
          <Space wrap>
            {!embedded && (
              <ProjectPicker
                projects={projects}
                value={projectId}
                onChange={(id) => {
                  storeProjectId(id)
                  setProjectId(id)
                }}
              />
            )}
            {!readOnly && !compact && (
              <Button type="primary" icon={<UploadOutlined />} disabled={!projectId} onClick={() => setUploadOpen(true)}>
                Upload documents
              </Button>
            )}
          </Space>
        </div>
      )}

      <DataTable
        columns={columns}
        data={documents}
        rowKey="id"
        searchPlaceholder="Search by file name or document type"
        emptyText="No current documents."
        className={compact ? 'pms-datatable-limited' : undefined}
        hideSearch={compact}
      />

      <DocumentUploadModal
        open={uploadOpen}
        projectId={projectId}
        onCancel={() => setUploadOpen(false)}
        onUploaded={() => loadDocuments(projectId)}
      />

      <Modal
        title={viewTarget?.file_name}
        open={viewTarget !== null}
        onCancel={closeView}
        destroyOnHidden
        width={MODAL_WIDTH.xl}
        className="pms-modal-xl"
        footer={[
          <Button key="close" onClick={closeView}>
            Close
          </Button>,
        ]}
      >
        <Descriptions column={1} bordered size="small" className="mb-4">
          <Descriptions.Item label="File name">{viewTarget?.file_name}</Descriptions.Item>
          <Descriptions.Item label="Type">{viewTarget?.document_type}</Descriptions.Item>
          <Descriptions.Item label="Activity">
            {viewTarget?.activity?.name ||
              (viewTarget?.requirement ? `Requirement ${viewTarget.requirement.requirement_code}` : 'Project-level')}
          </Descriptions.Item>
          <Descriptions.Item label="Version">v{viewTarget?.version_number}</Descriptions.Item>
          {!compact && (
            <>
              <Descriptions.Item label="Review status">
                <ReviewStatusBadge status={viewTarget?.review_status} />
              </Descriptions.Item>
              <Descriptions.Item label="Reviewer comment">{viewTarget?.review_comment || '—'}</Descriptions.Item>
            </>
          )}
          <Descriptions.Item label="Uploaded by">{viewTarget?.uploader?.name || '—'}</Descriptions.Item>
          <Descriptions.Item label="Uploaded at">
            {viewTarget?.uploaded_at ? dayjs(viewTarget.uploaded_at).format('MMM D, YYYY h:mm A') : '—'}
          </Descriptions.Item>
        </Descriptions>

        <Button
          icon={<DownloadOutlined />}
          className="mb-4"
          onClick={() => viewTarget && downloadFile(viewTarget)}
        >
          Download
        </Button>
      </Modal>
    </div>
  )
}

export default DocumentList
