import { Suspense, lazy, useCallback, useEffect, useMemo, useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import {
  Alert,
  Button,
  Card,
  Descriptions,
  Form,
  Input,
  Modal,
  Popconfirm,
  Select,
  Space,
  Spin,
  Table,
  Tabs,
  Tag,
  message,
} from 'antd'
import { CloseCircleOutlined, DownloadOutlined, PlusOutlined } from '@ant-design/icons'
import { Search } from 'lucide-react'
import api from '../lib/axios'
import { fetchProjectsCached } from '../lib/projectsCache'
import { fetchCached } from '../lib/cachedApi'
import { fetchAuthorizedFileUrl, storeProjectId, unwrapItem, unwrapList } from '../lib/apiHelpers'
import { useAuth } from '../context/AuthContext'
import { ROLES } from '../utility/Config.jsx'
import { deriveStatus } from '../lib/status'
import { formatDate } from '../lib/dates'
import InitiationDocumentsPanel from '../components/projects/InitiationDocumentsPanel'
import { MODAL_WIDTH } from '../lib/modalSizes'

const ProjectRegistration = lazy(() => import('./ProjectRegistration'))
const ProjectDetailsView = lazy(() => import('../components/projects/ProjectDetailsView'))

const DERIVED_STATUS_LABELS = { not_started: 'Not started', ongoing: 'Ongoing', completed: 'Completed' }
const LIFECYCLE_STAGE_LABELS = { initiation: 'Initiation', planning: 'Planning', execution: 'Execution', closure: 'Closure' }

const STATUS_COLOR = {
  Initiated: 'default',
  Planning: 'purple',
  'Plan Submitted': 'gold',
  'Plan Returned': 'orange',
  'Plan Approved': 'green',
  'In Execution': 'blue',
  Closed: 'red',
}

// Display-only relabeling â€” the stored status value stays "Plan Submitted"
// (other logic keys off it); reviewers just read it as "Pending Review".
const STATUS_LABEL = {
  'Plan Submitted': 'Pending Review',
}

function statusLabel(status) {
  return STATUS_LABEL[status] || status || '—'
}

function hasPermission(user, code) {
  return (user?.permissions || []).includes(code)
}

function isAssignedToPlanner(project, user) {
  if (!user) return false
  if (project.planner_id != null && Number(project.planner_id) === Number(user.id)) return true
  const userName = user.name || user.full_name
  if (userName && project.planner?.name === userName) return true
  return false
}

export function isCompletedProject(project) {
  return Boolean(project?.closed_at) || project?.status === 'Closed'
}

function ProjectsPage({ mode = 'browse' } = {}) {
  const { user, activeRole } = useAuth()
  const [searchParams, setSearchParams] = useSearchParams()
  const [projects, setProjects] = useState([])
  const [users, setUsers] = useState([])
  const [loading, setLoading] = useState(false)
  const [search, setSearch] = useState(() => searchParams.get('q') || '')
  const derivedStatusFilter = searchParams.get('derivedStatus')
  const lifecycleStageFilter = searchParams.get('lifecycleStage')
  const view = searchParams.get('view') === 'completed' ? 'completed' : 'ongoing'
  const [reassignTarget, setReassignTarget] = useState(null)
  const [detailTarget, setDetailTarget] = useState(null)
  const [detailWorkflow, setDetailWorkflow] = useState(null)
  const [requestingClosure, setRequestingClosure] = useState(false)
  const [downloadingArchive, setDownloadingArchive] = useState(false)
  const [signingOffClosure, setSigningOffClosure] = useState(false)
  const [returningClosure, setReturningClosure] = useState(false)
  const [closureReturnOpen, setClosureReturnOpen] = useState(false)
  const [closureReturnComment, setClosureReturnComment] = useState('')
  const [closureCompletedActivities, setClosureCompletedActivities] = useState([])
  const [closureCompletedRequirements, setClosureCompletedRequirements] = useState([])
  const [closureActivityIds, setClosureActivityIds] = useState([])
  const [closureRequirementIds, setClosureRequirementIds] = useState([])
  const [closureItemsLoading, setClosureItemsLoading] = useState(false)
  const [activityReviewTarget, setActivityReviewTarget] = useState(null)
  const [activityReviewDocs, setActivityReviewDocs] = useState([])
  const [activityReviewDocsLoading, setActivityReviewDocsLoading] = useState(false)
  const [activityReviewComment, setActivityReviewComment] = useState('')
  const [isRejectingActivity, setIsRejectingActivity] = useState(false)
  const [activityRejectReason, setActivityRejectReason] = useState('')
  const [activityReviewSaving, setActivityReviewSaving] = useState(false)
  const [downloadingDocId, setDownloadingDocId] = useState(null)
  const [previewDoc, setPreviewDoc] = useState(null)
  const [previewUrl, setPreviewUrl] = useState(null)
  const [previewLoading, setPreviewLoading] = useState(false)
  const [, setActionsOpenId] = useState(null)
  const [saving, setSaving] = useState(false)
  const [registerOpen, setRegisterOpen] = useState(false)
  const [registerLoaded, setRegisterLoaded] = useState(false)
  const [detailFooterAction, setDetailFooterAction] = useState(null)
  const [form] = Form.useForm()

  const isPlannerRole = activeRole?.name === ROLES.PPL
  const isReviewerRole = activeRole?.name === ROLES.PRV
  const isApproverRole = activeRole?.name === ROLES.PAP
  const isCoordinatorRole = activeRole?.name === ROLES.PCO
  // Reviewer: `/review-project` keeps full review actions; `/projects` is browse-only.
  const isReviewWorkflow = isReviewerRole && mode === 'review'
  const isBrowseMode = isReviewerRole && !isReviewWorkflow
  const canReassign =
    hasPermission(user, 'projects.assign_planner') || hasPermission(user, 'projects.reassign_planner')
  const isCompletedView = view === 'completed'
  const canRegister =
    !isReviewWorkflow &&
    activeRole?.name !== ROLES.PPL &&
    (activeRole?.name === ROLES.PRV || activeRole?.name === ROLES.PAD) &&
    hasPermission(user, 'projects.register')

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const projectParams = {}
      if (isPlannerRole && user?.id) {
        projectParams.planner_id = user.id
        projectParams.role = ROLES.PPL
      } else if (isApproverRole) {
        // History of projects this Approver has signed off.
        projectParams.role = ROLES.PAP
      } else if (isCoordinatorRole) {
        // History of projects this Coordinator has recommended.
        projectParams.role = ROLES.PCO
      }

      const projectsRes = await fetchProjectsCached(projectParams)

      let list = unwrapList(projectsRes.data)
      if (isPlannerRole && user) {
        list = list.filter((project) => isAssignedToPlanner(project, user))
      }

      setProjects(list)
    } catch (err) {
      message.error(err.response?.data?.message || 'Could not load projects.')
    } finally {
      setLoading(false)
    }
  }, [isPlannerRole, isApproverRole, isCoordinatorRole, user])

  const loadUsersForReassign = useCallback(async () => {
    if (users.length) return
    try {
      const usersRes = await fetchCached('/users')
      setUsers(unwrapList(usersRes.data))
    } catch {
      message.error('Could not load planners.')
    }
  }, [users.length])

  const loadDetailWorkflow = (projectId) => {
    fetchCached(`/projects/${projectId}`, { params: { summary: 1 } })
      .then((response) => {
        setDetailWorkflow(response.data?.workflow ?? null)
        setDetailTarget((previous) => previous?.id === projectId ? { ...previous, ...unwrapItem(response.data) } : previous)
      })
      .catch(() => setDetailWorkflow(null))
  }

  const openDetail = (record) => {
    setActionsOpenId(null)
    setDetailTarget(record)
    setDetailWorkflow(null)
    storeProjectId(record.id)
    loadDetailWorkflow(record.id)

  }

  useEffect(() => {
    load()
  }, [load])

  useEffect(() => {
    const detailId = Number(searchParams.get('detail'))
    if (!Number.isFinite(detailId) || detailId <= 0) return

    const project = projects.find((item) => item.id === detailId)
    if (!project) return

    if (!detailTarget || detailTarget.id !== detailId) {
      openDetail(project)
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps -- open when list loads with ?detail=
  }, [projects, searchParams])

  // After Register project → land on Ongoing list, toast, and refresh.
  const handleRegistered = ({ id, name }) => {
    setRegisterOpen(false)
    if (searchParams.get('view') === 'completed') {
      const next = new URLSearchParams(searchParams)
      next.delete('view')
      setSearchParams(next, { replace: true })
    }
    message.success(`Project "${name}" registered successfully`)
    storeProjectId(id)
    load()
  }

  const planners = useMemo(() => {
    const filtered = users.filter((item) =>
      (item.roles || []).some((role) => role.name === ROLES.PPL),
    )
    return filtered.length ? filtered : users
  }, [users])

  const filtered = useMemo(() => {
    const term = search.trim().toLowerCase()
    return projects.filter((project) => {
      const completed = isCompletedProject(project)
      if (isCompletedView && !completed) return false
      if (!isCompletedView && completed) return false
      if (derivedStatusFilter && deriveStatus(project) !== derivedStatusFilter) return false
      if (lifecycleStageFilter && project.lifecycle_stage !== lifecycleStageFilter) return false
      if (!term) return true
      return [project.name, project.category, project.project_type, project.status, project.phase, project.planner?.name]
        .filter(Boolean)
        .join(' ')
        .toLowerCase()
        .includes(term)
    })
  }, [projects, search, derivedStatusFilter, lifecycleStageFilter, isCompletedView])

  const clearStructuredFilter = () => {
    const next = new URLSearchParams(searchParams)
    next.delete('derivedStatus')
    next.delete('lifecycleStage')
    setSearchParams(next, { replace: true })
  }

  const handleWorkspaceChanged = () => {
    if (!detailTarget) return
    loadDetailWorkflow(detailTarget.id)
    load()
  }

  const requestClosure = async () => {
    if (!detailTarget) return
    setRequestingClosure(true)
    try {
      await api.post(`/projects/${detailTarget.id}/closure/request`)
      message.success('Closure requested')
      handleWorkspaceChanged()
    } catch (err) {
      message.error(err.response?.data?.message || 'Could not request closure.')
    } finally {
      setRequestingClosure(false)
    }
  }

  const signOffClosure = async () => {
    if (!detailTarget) return
    setSigningOffClosure(true)
    try {
      await api.post(`/projects/${detailTarget.id}/close`)
      message.success('Project closed')
      handleWorkspaceChanged()
    } catch (err) {
      message.error(err.response?.data?.message || 'Could not close the project.')
    } finally {
      setSigningOffClosure(false)
    }
  }

  const downloadProjectArchive = async () => {
    if (!detailTarget) return
    setDownloadingArchive(true)
    try {
      const response = await api.get(`/projects/${detailTarget.id}/archive`, { responseType: 'blob' })
      const blob = new Blob([response.data], { type: 'application/zip' })
      const url = URL.createObjectURL(blob)
      const link = document.createElement('a')
      link.href = url
      link.download = `${detailTarget.name || 'project'}-archive.zip`
      link.click()
      URL.revokeObjectURL(url)
      setDetailTarget(null)
    } catch {
      message.error('Could not download the project archive.')
    } finally {
      setDownloadingArchive(false)
    }
  }

  const openClosureReturn = async () => {
    if (!detailTarget) return
    setClosureReturnOpen(true)
    setClosureItemsLoading(true)
    try {
      const [activitiesRes, requirementsRes] = await Promise.all([
        api.get(`/projects/${detailTarget.id}/activities`),
        api.get(`/projects/${detailTarget.id}/requirements`),
      ])
      setClosureCompletedActivities(unwrapList(activitiesRes.data).filter((item) => item.actual_end_date))
      setClosureCompletedRequirements(unwrapList(requirementsRes.data).filter((item) => item.actual_end_date))
    } catch {
      setClosureCompletedActivities([])
      setClosureCompletedRequirements([])
    } finally {
      setClosureItemsLoading(false)
    }
  }

  const submitReturnClosure = async () => {
    if (!detailTarget) return
    if (!closureReturnComment.trim()) {
      message.error('Add a comment explaining what needs fixing')
      return
    }
    setReturningClosure(true)
    try {
      await api.post(`/projects/${detailTarget.id}/closure/return`, {
        comment: closureReturnComment.trim(),
        activity_ids: closureActivityIds,
        requirement_ids: closureRequirementIds,
      })
      message.success('Returned to planner')
      setClosureReturnOpen(false)
      setClosureReturnComment('')
      setClosureActivityIds([])
      setClosureRequirementIds([])
      handleWorkspaceChanged()
    } catch (err) {
      message.error(err.response?.data?.message || 'Could not return to planner.')
    } finally {
      setReturningClosure(false)
    }
  }

  const openReassign = (record) => {
    setActionsOpenId(null)
    setReassignTarget(record)
    form.setFieldsValue({ planner_id: record.planner_id })
    loadUsersForReassign()
  }

  const viewDocument = async (doc) => {
    setPreviewDoc(doc)
    setPreviewUrl(null)
    setPreviewLoading(true)
    try {
      const url = await fetchAuthorizedFileUrl(doc.id)
      setPreviewUrl(url)
    } catch {
      message.error('Could not open document.')
      setPreviewDoc(null)
    } finally {
      setPreviewLoading(false)
    }
  }

  const closePreview = () => {
    if (previewUrl) URL.revokeObjectURL(previewUrl)
    setPreviewDoc(null)
    setPreviewUrl(null)
  }

  const downloadSingleDocument = async (doc) => {
    setDownloadingDocId(doc.id)
    try {
      const url = await fetchAuthorizedFileUrl(doc.id)
      const link = document.createElement('a')
      link.href = url
      link.download = doc.file_name || 'document'
      link.click()
      URL.revokeObjectURL(url)
    } catch {
      message.error('Could not download document.')
    } finally {
      setDownloadingDocId(null)
    }
  }

  const openActivityReview = (activity) => {
    setActivityReviewComment('')
    setActivityRejectReason('')
    setIsRejectingActivity(false)
    setActivityReviewTarget(activity)
    setActivityReviewDocs([])
    setActivityReviewDocsLoading(true)
    api
      .get(`/projects/${activity.project_id}/documents`, { params: { activity_id: activity.id } })
      .then((response) => setActivityReviewDocs(unwrapList(response.data)))
      .catch(() => setActivityReviewDocs([]))
      .finally(() => setActivityReviewDocsLoading(false))
  }

  const closeActivityReview = () => {
    setActivityReviewTarget(null)
    setActivityReviewDocs([])
    setActivityReviewComment('')
    setActivityRejectReason('')
    setIsRejectingActivity(false)
  }

  const submitActivityReview = async (decision) => {
    if (!activityReviewTarget) return

    if (decision === 'reject' && !isRejectingActivity) {
      setIsRejectingActivity(true)
      return
    }

    const isPlanSubmissionReview =
      detailTarget?.plan_review_status === 'pending_review' &&
      activityReviewTarget.progress_review_status !== 'pending' &&
      activityReviewTarget.plan_change_status !== 'pending'

    if (isPlanSubmissionReview) {
      const comment =
        decision === 'reject' ? activityRejectReason.trim() : activityReviewComment.trim()
      if (decision === 'reject' && !comment) {
        message.error('Please provide a reason for rejecting.')
        return
      }
      setActivityReviewSaving(true)
      try {
        const response = await api.post(`/projects/${detailTarget.id}/plan/review`, {
          decision: decision === 'approve' ? 'approved' : 'returned',
          comment: comment || undefined,
        })
        const updated = unwrapItem(response.data)
        setDetailTarget((prev) => (prev ? { ...prev, ...updated } : updated))
        closeActivityReview()
        loadDetailWorkflow(detailTarget.id)
        load()
      } catch (err) {
        message.error(err.response?.data?.message || 'Could not submit the plan decision.')
      } finally {
        setActivityReviewSaving(false)
      }
      return
    }

    // A progress update in flight is the only thing routed to the
    // progress-review endpoints; everything else (an explicit 'pending'
    // plan_change_status, or null on an activity created before that field
    // was tracked) goes through plan-changes, which tolerates a missing
    // pending_changes snapshot.
    const kind = activityReviewTarget.progress_review_status === 'pending' ? 'progress-review' : 'plan-changes'
    let payload = {}
    if (decision === 'reject') {
      const reason = activityRejectReason.trim()
      if (!reason) {
        message.error('Please provide a reason for rejecting.')
        return
      }
      payload = { comment: reason }
    } else {
      const comment = activityReviewComment.trim()
      if (comment) payload = { comment }
    }

    setActivityReviewSaving(true)
    try {
      await api.post(`/activities/${activityReviewTarget.id}/${kind}/${decision}`, payload)
      closeActivityReview()
      handleWorkspaceChanged()
    } catch (err) {
      message.error(err.response?.data?.message || 'Could not submit the review decision.')
    } finally {
      setActivityReviewSaving(false)
    }
  }

  const submitReassign = async (values) => {
    if (!reassignTarget) return
    setSaving(true)
    try {
      await api.put(`/projects/${reassignTarget.id}/reassign`, { planner_id: values.planner_id })
      message.success('Planner reassigned.')
      setReassignTarget(null)
      form.resetFields()
      await load()
    } catch (err) {
      message.error(err.response?.data?.message || 'Could not reassign planner.')
    } finally {
      setSaving(false)
    }
  }

  const detailInExecution = Boolean(detailTarget?.execution_started_at || detailWorkflow?.execution_started_at)

  const activityNeedsReview = (activityRecord) => {
    if (!isReviewWorkflow) return false
    if (activityRecord.progress_review_status === 'pending') return true
    if (activityRecord.plan_change_status === 'pending') return true
    if (detailTarget?.plan_review_status === 'pending_review') return true
    return false
  }

  const activityPendingMessage = (activityRecord) => {
    if (activityRecord.progress_review_status === 'pending') {
      return 'A progress update is awaiting your decision.'
    }
    if (activityRecord.plan_change_status === 'pending') {
      return 'A plan change is awaiting your decision.'
    }
    if (detailTarget?.plan_review_status === 'pending_review') {
      return 'This activity is awaiting your decision.'
    }
    return 'This activity is awaiting your decision.'
  }

  const columns = [
    {
      title: 'SN',
      key: 'sn',
      width: 56,
      align: 'center',
      onHeaderCell: () => ({ style: { whiteSpace: 'nowrap' } }),
      render: (_, __, index) => index + 1,
    },
    {
      title: 'Project Name',
      dataIndex: 'name',
      key: 'name',
      ellipsis: true,
      onHeaderCell: () => ({ style: { whiteSpace: 'nowrap' } }),
      render: (value) => <span className="font-medium">{value}</span>,
    },
    {
      title: 'Category',
      dataIndex: 'category',
      key: 'category',
      onHeaderCell: () => ({ style: { whiteSpace: 'nowrap' } }),
    },
    {
      title: 'Type',
      dataIndex: 'project_type',
      key: 'project_type',
      ellipsis: true,
      onHeaderCell: () => ({ style: { whiteSpace: 'nowrap' } }),
    },
    {
      title: 'Planner',
      key: 'planner',
      ellipsis: true,
      onHeaderCell: () => ({ style: { whiteSpace: 'nowrap' } }),
      render: (_, record) => record.planner?.name || 'Unassigned',
    },
    {
      title: 'Status',
      key: 'status',
      width: 140,
      align: 'left',
      onHeaderCell: () => ({ style: { whiteSpace: 'nowrap' } }),
      onCell: () => ({ style: { whiteSpace: 'nowrap' } }),
      render: (_, record) => (
        <Tag
          color={STATUS_COLOR[record.status] || 'default'}
          style={{ marginInlineEnd: 0, whiteSpace: 'nowrap' }}
        >
          {statusLabel(record.status)}
        </Tag>
      ),
    },
    {
      title: 'Action',
      key: 'actions',
      width: 110,
      align: 'center',
      onHeaderCell: () => ({ style: { whiteSpace: 'nowrap' } }),
      render: (_, record) => (
        <Space size="small" wrap>
          <Button
            type="primary"
            aria-label={isReviewWorkflow ? 'Review project' : 'View project details'}
            style={{ backgroundColor: '#800000', borderColor: '#800000' }}
            onClick={() => openDetail(record)}
          >
            {isReviewWorkflow ? 'Review' : 'View'}
          </Button>
        </Space>
      ),
    },
  ]

  return (
    <div>
      {/* One 12px gutter, not the card's 1rem class padding plus a body padding on top. */}
      <Card className="page-shell-card" style={{ padding: 12, marginTop: 0 }} styles={{ body: { padding: 0 } }}>
        <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
          <Tabs
            type="card"
            activeKey={view}
            className="pms-view-tabs !mb-0"
            tabBarStyle={{ marginBottom: 0 }}
            onChange={(key) => {
              const next = new URLSearchParams(searchParams)
              if (key === 'completed') next.set('view', 'completed')
              else next.delete('view')
              setSearchParams(next, { replace: true })
            }}
            items={[
              { key: 'ongoing', label: 'Ongoing' },
              { key: 'completed', label: 'Completed' },
            ]}
          />
          {canRegister && !isCompletedView && (
            <Button
              type="primary"
              icon={<PlusOutlined />}
              style={{ backgroundColor: '#800000', borderColor: '#800000', height: 42 }}
              onClick={() => {
                setRegisterLoaded(true)
                setRegisterOpen(true)
              }}
            >
              Register project
            </Button>
          )}
        </div>
        {(derivedStatusFilter || lifecycleStageFilter) && (
          <Tag
            closable
            closeIcon={<CloseCircleOutlined />}
            onClose={clearStructuredFilter}
            color="#962c30"
            className="mb-3"
          >
            Filtered by:{' '}
            {[
              derivedStatusFilter && (DERIVED_STATUS_LABELS[derivedStatusFilter] ?? derivedStatusFilter),
              lifecycleStageFilter && (LIFECYCLE_STAGE_LABELS[lifecycleStageFilter] ?? lifecycleStageFilter),
            ]
              .filter(Boolean)
              .join(' · ')}
          </Tag>
        )}
        <Input
          allowClear
          prefix={<Search className="h-4 w-4 text-gray-400" />}
          placeholder="Search name, category, planner, status"
          className="mb-3 max-w-md"
          value={search}
          onChange={(event) => setSearch(event.target.value)}
        />
        <Table
          className="pms-house-table"
          rowKey="id"
          loading={loading}
          columns={columns}
          dataSource={filtered}
          pagination={{ pageSize: 10, showSizeChanger: true }}
          locale={{
            emptyText: isCompletedView
              ? 'No completed projects yet.'
              : isPlannerRole
                ? 'No projects assigned to you.'
                : 'No ongoing projects.',
          }}
        />
      </Card>

      {registerLoaded && (
        <Suspense fallback={null}>
          <ProjectRegistration
            open={registerOpen}
            onClose={() => setRegisterOpen(false)}
            onRegistered={handleRegistered}
          />
        </Suspense>
      )}

      <Modal
        title={null}
        open={Boolean(detailTarget)}
        onCancel={() => {
          setDetailTarget(null)
          setDetailFooterAction(null)
          closeActivityReview()
        }}
        destroyOnHidden
        width={MODAL_WIDTH.xl}
        className="pms-modal-xl"
        centered
        styles={{ body: { overflowY: 'auto', paddingTop: 20, paddingRight: 16, paddingLeft: 16 } }}
        footer={
          <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8 }}>
            {detailFooterAction && (
              <Button
                type="primary"
                style={{ backgroundColor: '#7A0C22', borderColor: '#7A0C22' }}
                onClick={detailFooterAction.onClick}
              >
                {detailFooterAction.label}
              </Button>
            )}
            {isPlannerRole && detailWorkflow?.can_request_closure && (
              <Popconfirm
                title="Request closure?"
                description="This tells the Reviewer every activity and requirement is finished and ready for sign-off."
                okText="Request closure"
                onConfirm={requestClosure}
              >
                <Button type="primary" loading={requestingClosure}>
                  Request closure
                </Button>
              </Popconfirm>
            )}
            {isReviewWorkflow && detailWorkflow?.can_approve_closure && (
              <>
                <Popconfirm
                  title="Sign off and close this project?"
                  description="This is final — the project moves to Closed and cannot be reopened."
                  okText="Sign off and close"
                  onConfirm={signOffClosure}
                >
                  <Button type="primary" loading={signingOffClosure}>
                    Sign off and close
                  </Button>
                </Popconfirm>
                <Button onClick={openClosureReturn}>Return to planner</Button>
              </>
            )}
            {detailTarget?.closed_at && (
              <Button icon={<DownloadOutlined />} loading={downloadingArchive} onClick={downloadProjectArchive}>
                Download project
              </Button>
            )}
            <Button
              type="default"
              onClick={() => {
                setDetailTarget(null)
                setDetailFooterAction(null)
              }}
            >
              Close
            </Button>
          </div>
        }
      >
        {detailTarget && (
          <Suspense fallback={<Spin size="large" />}><ProjectDetailsView
            key={detailTarget.id}
            project={detailTarget}
            onProjectChanged={handleWorkspaceChanged}
            readOnlyBrowse={isBrowseMode}
            onActivityReview={isReviewWorkflow ? openActivityReview : undefined}
            shouldShowActivityReview={isReviewWorkflow ? activityNeedsReview : undefined}
            canReassign={isReviewWorkflow && canReassign}
            onReassign={openReassign}
            onFooterActionChange={setDetailFooterAction}
            alerts={
              <>
                {detailWorkflow?.closure_return_comment &&
                  !detailWorkflow?.closure_requested_at &&
                  !detailWorkflow?.closed_at && (
                    <Alert
                      type="warning"
                      showIcon
                      message="Closure returned"
                      description={detailWorkflow.closure_return_comment}
                    />
                  )}
                {detailTarget.plan_review_status === 'approved' &&
                  !detailInExecution &&
                  detailWorkflow?.execution_blockers?.length > 0 && (
                    <Alert
                      type="info"
                      showIcon
                      message="Next step: move to execution"
                      description={
                        <ul className="mb-0 mt-1 list-disc pl-5">
                          {detailWorkflow.execution_blockers.map((item) => (
                            <li key={item}>{item}</li>
                          ))}
                        </ul>
                      }
                    />
                  )}
              </>
            }
            initiationPanel={
              detailTarget.lifecycle_stage === 'initiation' && canRegister ? (
                <InitiationDocumentsPanel
                  projectId={detailTarget.id}
                  onProceeded={() => {
                    setDetailTarget(null)
                    load()
                  }}
                />
              ) : null
            }
          /></Suspense>
        )}
      </Modal>

      <Modal
        title={<span style={{ color: '#800000', fontWeight: 700 }}>Review activity</span>}
        open={Boolean(activityReviewTarget)}
        onCancel={closeActivityReview}
        destroyOnHidden
        centered
        width={MODAL_WIDTH.lg}
        className="pms-modal-lg"
        zIndex={1100}
        maskClosable={false}
        footer={
          <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8 }}>
            {isRejectingActivity ? (
              <>
                <Button danger loading={activityReviewSaving} onClick={() => submitActivityReview('reject')}>
                  Confirm reject
                </Button>
                <Button onClick={() => setIsRejectingActivity(false)}>Back</Button>
              </>
            ) : (
              <>
                <Button
                  type="primary"
                  style={{ backgroundColor: '#800000', borderColor: '#800000' }}
                  loading={activityReviewSaving}
                  onClick={() => submitActivityReview('approve')}
                >
                  Approve
                </Button>
                <Button danger loading={activityReviewSaving} onClick={() => submitActivityReview('reject')}>
                  Reject
                </Button>
                <Button onClick={closeActivityReview}>Cancel</Button>
              </>
            )}
          </div>
        }
      >
        {activityReviewTarget && (
          <div>
            <Descriptions column={1} size="small" bordered>
              <Descriptions.Item label="Activity">{activityReviewTarget.name}</Descriptions.Item>
              <Descriptions.Item label="Expected Deliverable">
                {activityReviewTarget.expected_deliverable || 'â€”'}
              </Descriptions.Item>
              <Descriptions.Item label="Planned Start">
                {formatDate(activityReviewTarget.planned_start_date)}
              </Descriptions.Item>
              <Descriptions.Item label="Planned End">
                {formatDate(activityReviewTarget.planned_end_date)}
              </Descriptions.Item>
              <Descriptions.Item label="Responsible Person">
                {activityReviewTarget.responsible_person?.name || 'â€”'}
              </Descriptions.Item>
              {activityReviewTarget.progress_review_status === 'pending' && (
                <>
                  <Descriptions.Item label="Actual Start">
                    {formatDate(activityReviewTarget.actual_start_date)}
                  </Descriptions.Item>
                  <Descriptions.Item label="Actual End">
                    {formatDate(activityReviewTarget.actual_end_date)}
                  </Descriptions.Item>
                </>
              )}
              <Descriptions.Item label="What's pending">
                {activityPendingMessage(activityReviewTarget)}
              </Descriptions.Item>
            </Descriptions>

            <div className="mt-4">
              <div className="mb-1 text-sm font-semibold">Documents</div>
              <Spin spinning={activityReviewDocsLoading}>
                <Table
                  rowKey="id"
                  size="small"
                  dataSource={activityReviewDocs}
                  pagination={false}
                  locale={{ emptyText: 'No documents attached to this activity.' }}
                  columns={[
                    { title: 'File', dataIndex: 'file_name' },
                    { title: 'Document Type', dataIndex: 'document_type', render: (value) => value || 'Document' },
                    {
                      title: 'Action',
                      width: 100,
                      render: (_, doc) => (
                        <Button size="small" onClick={() => viewDocument(doc)}>
                          View
                        </Button>
                      ),
                    },
                  ]}
                />
              </Spin>
            </div>

            {isRejectingActivity ? (
              <div className="mt-4">
                <div className="mb-1 text-sm font-semibold text-red-600">Reason for rejection (required)</div>
                <Input.TextArea
                  rows={3}
                  autoFocus
                  placeholder="Explain what needs to change before this can be approved"
                  value={activityRejectReason}
                  onChange={(event) => setActivityRejectReason(event.target.value)}
                />
              </div>
            ) : (
              <div className="mt-4">
                <div className="mb-1 text-sm font-semibold">Comment (optional)</div>
                <Input.TextArea
                  rows={3}
                  placeholder="Any remarks about this activity"
                  value={activityReviewComment}
                  onChange={(event) => setActivityReviewComment(event.target.value)}
                />
              </div>
            )}
          </div>
        )}
      </Modal>

      <Modal
        title={reassignTarget ? `Reassign planner — ${reassignTarget.name}` : 'Reassign planner'}
        open={Boolean(reassignTarget)}
        onCancel={() => {
          setReassignTarget(null)
          form.resetFields()
        }}
        onOk={() => form.submit()}
        confirmLoading={saving}
        width={MODAL_WIDTH.md}
        className="pms-modal-md"
        footer={(_, { OkBtn, CancelBtn }) => (
          <>
            <OkBtn />
            <CancelBtn />
          </>
        )}
        destroyOnHidden
      >
        <Form form={form} layout="vertical" onFinish={submitReassign} className="pt-2">
          <Form.Item name="planner_id" label="Planner" rules={[{ required: true, message: 'Select a planner' }]}>
            <Select
              showSearch
              optionFilterProp="label"
              options={planners.map((item) => ({
                value: item.id,
                label: `${item.name} (${item.email})`,
              }))}
            />
          </Form.Item>
        </Form>
      </Modal>

      <Modal
        title="Return to planner"
        open={closureReturnOpen}
        onCancel={() => {
          setClosureReturnOpen(false)
          setClosureReturnComment('')
          setClosureActivityIds([])
          setClosureRequirementIds([])
        }}
        destroyOnHidden
        footer={
          <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8 }}>
            <Button type="primary" loading={returningClosure} onClick={submitReturnClosure}>
              Return to planner
            </Button>
            <Button
              onClick={() => {
                setClosureReturnOpen(false)
                setClosureReturnComment('')
                setClosureActivityIds([])
                setClosureRequirementIds([])
              }}
            >
              Cancel
            </Button>
          </div>
        }
      >
        <p className="mb-3 text-sm text-gray-600">
          Clears the closure request and sends the project back to the Planner. Pick any completed activities or
          requirements that actually need rework — selecting one reopens it (clears its actual end date, and its
          test result if it's a requirement) so the Planner can update and redo it.
        </p>

        <div className="mb-3">
          <div className="mb-1 text-sm font-medium">Activities to reopen</div>
          <Select
            mode="multiple"
            allowClear
            style={{ width: '100%' }}
            placeholder={closureCompletedActivities.length ? 'Select activities that need rework' : 'No completed activities'}
            loading={closureItemsLoading}
            value={closureActivityIds}
            onChange={setClosureActivityIds}
            options={closureCompletedActivities.map((item) => ({ value: item.id, label: item.name }))}
          />
        </div>

        <div className="mb-3">
          <div className="mb-1 text-sm font-medium">Requirements to reopen</div>
          <Select
            mode="multiple"
            allowClear
            style={{ width: '100%' }}
            placeholder={
              closureCompletedRequirements.length ? 'Select requirements that need rework' : 'No completed requirements'
            }
            loading={closureItemsLoading}
            value={closureRequirementIds}
            onChange={setClosureRequirementIds}
            options={closureCompletedRequirements.map((item) => ({
              value: item.id,
              label: `${item.requirement_code} — ${item.description}`,
            }))}
          />
        </div>

        <div className="mb-1 text-sm font-medium">Comment</div>
        <Input.TextArea
          rows={3}
          value={closureReturnComment}
          onChange={(event) => setClosureReturnComment(event.target.value)}
          placeholder="What still needs to be fixed before this can close?"
        />
      </Modal>

      <Modal
        title={previewDoc?.file_name}
        open={previewDoc !== null}
        onCancel={closePreview}
        destroyOnHidden
        width={MODAL_WIDTH.xl}
        className="pms-modal-xl"
        footer={
          <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8 }}>
            <Button
              icon={<DownloadOutlined />}
              loading={downloadingDocId === previewDoc?.id}
              onClick={() => previewDoc && downloadSingleDocument(previewDoc)}
            >
              Download
            </Button>
            <Button onClick={closePreview}>Close</Button>
          </div>
        }
      >
        {previewLoading ? (
          <div className="flex justify-center py-16">
            <Spin />
          </div>
        ) : previewUrl && previewDoc?.file_name?.toLowerCase().endsWith('.pdf') ? (
          <iframe
            src={previewUrl}
            title={previewDoc.file_name}
            style={{ width: '100%', height: '100%', minHeight: '55vh', border: 'none' }}
          />
        ) : previewUrl ? (
          <div className="py-16 text-center text-sm text-gray-500">
            Preview isn't available for this file type — use Download to view it.
          </div>
        ) : null}
      </Modal>
    </div>
  )
}

export default ProjectsPage
