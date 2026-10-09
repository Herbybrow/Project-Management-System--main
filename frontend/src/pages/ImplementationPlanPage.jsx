import { useCallback, useEffect, useState } from 'react'
import { createPortal } from 'react-dom'
import { useParams, useSearchParams } from 'react-router-dom'
import { message, Modal, Space, Spin, Alert, Button, Table, Tag, Descriptions, Input, Popconfirm } from 'antd'
import { PlusOutlined, EyeOutlined, DownloadOutlined } from '@ant-design/icons'
import { Plus } from 'lucide-react'
import dayjs from 'dayjs'
import { deriveStatus } from '../lib/status'
import { formatDate } from '../lib/dates'
import { MODAL_WIDTH } from '../lib/modalSizes'
import ActivityFormModal from '../components/activities/ActivityFormModal'
import ActivityReviewDrawer from '../components/activities/ActivityReviewDrawer'
import ActivityDetailsModal from '../components/activities/ActivityDetailsModal'
import AddRtmModal from '../components/activities/AddRtmModal'
import ActivitiesTable from '../components/activities/ActivitiesTable'
import WorkflowBar from '../components/activities/WorkflowBar'
import ActivityDocumentsModal from '../components/activities/ActivityDocumentsModal'
import RequirementProgressModal from '../components/matrix/RequirementProgressModal'
import TestScoreModal from '../components/matrix/TestScoreModal'
import ProjectPicker from '../components/common/ProjectPicker'
import PreventMutation from '../components/common/PreventMutation'
import { isSpecReadOnlyRole, useActiveRoleName } from '../components/common/RoleGuard'
import { ROLES } from '../utility/Config.jsx'
import api from '../lib/axios'
import { fetchProjectsCached } from '../lib/projectsCache'
import { fetchCached } from '../lib/cachedApi'
import { useRequestCacheRevision } from '../lib/useRequestCacheRevision'
import { submitPlanForReview } from '../api/planner'
import {
  getMissingRequiredDocumentTypes,
  normalizeProjectDocumentUploads,
  REQUIRED_PROJECT_DOCUMENT_TYPES,
} from '../lib/projectDocuments'
import {
  apiTestResultToUi,
  fetchAuthorizedFileUrl,
  getStoredProjectId,
  storeProjectId,
  uiTestResultToApi,
  unwrapItem,
  unwrapList,
} from '../lib/apiHelpers'

const BLANK_ACTIVITY = {
  id: null,
  name: '',
  expected_deliverable: '',
  planned_start_date: null,
  planned_end_date: null,
  responsible_person_id: null,
}

function ImplementationPlanPage({
  embedded = false,
  toolbarContainer = null,
  projectId: projectIdProp = null,
  onActivityReview = null,
  onProjectChanged = null,
  shouldShowActivityReview = null,
  simplifiedPlannerView = false,
  hideExpectedDeliverable = false,
  hideReapprovalNotice = false,
  hideWorkflowBar = false,
  hideInlineAddActions = false,
  registerActions = null,
  readOnlyBrowse = false,
  workspaceSection = null,
  active = true,
} = {}) {
  const { id: routeId } = useParams()
  const [searchParams] = useSearchParams()
  const roleName = useActiveRoleName()
  const canAddActivity = !readOnlyBrowse && !isSpecReadOnlyRole(roleName) && roleName !== ROLES.PRV
  const [projects, setProjects] = useState([])
  const [users, setUsers] = useState([])
  const [projectId, setProjectId] = useState(() => {
    if (projectIdProp) return projectIdProp
    const fromRoute = Number(routeId)
    if (Number.isFinite(fromRoute) && fromRoute > 0) {
      storeProjectId(fromRoute)
      return fromRoute
    }
    const fromQuery = searchParams.get('projectId')
    if (fromQuery) {
      const parsed = Number(fromQuery)
      if (!Number.isNaN(parsed)) {
        storeProjectId(parsed)
        return parsed
      }
    }
    return getStoredProjectId()
  })
  const [workflow, setWorkflow] = useState(null)
  const [projectMeta, setProjectMeta] = useState(null)
  const [activities, setActivities] = useState([])
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState('')
  const [formTarget, setFormTarget] = useState(null)
  const [reviewTarget, setReviewTarget] = useState(null)
  const [reviewHistory, setReviewHistory] = useState([])
  const [docsTarget, setDocsTarget] = useState(null)
  const [filteredInfo, setFilteredInfo] = useState({})
  const [missingProjectDocTypes, setMissingProjectDocTypes] = useState([])
  const [savingActivity, setSavingActivity] = useState(false)
  const [editActivityDocs, setEditActivityDocs] = useState([])
  const [editActivityDocsLoading, setEditActivityDocsLoading] = useState(false)
  const [detailsTarget, setDetailsTarget] = useState(null)
  const [detailsDocs, setDetailsDocs] = useState([])
  const [detailsDocsLoading, setDetailsDocsLoading] = useState(false)
  const [activityChangeComment, setActivityChangeComment] = useState('')
  const [activityChangeSaving, setActivityChangeSaving] = useState(false)
  const [rtmModalOpen, setRtmModalOpen] = useState(false)
  const [requirements, setRequirements] = useState([])
  const [requirementsLoading, setRequirementsLoading] = useState(false)
  const [rtmViewTarget, setRtmViewTarget] = useState(null)
  const [rtmViewDocs, setRtmViewDocs] = useState([])
  const [rtmViewDocsLoading, setRtmViewDocsLoading] = useState(false)
  const [rtmSaving, setRtmSaving] = useState(false)
  const [rtmProgressTarget, setRtmProgressTarget] = useState(null)
  const [rtmTestTarget, setRtmTestTarget] = useState(null)
  const [rtmRejecting, setRtmRejecting] = useState(false)
  const [rtmRejectComment, setRtmRejectComment] = useState('')
  const [rtmEditTarget, setRtmEditTarget] = useState(null)
  const [rtmEditCode, setRtmEditCode] = useState('')
  const [rtmEditDescription, setRtmEditDescription] = useState('')
  const [rtmEditSaving, setRtmEditSaving] = useState(false)
  const revision = useRequestCacheRevision()

  const people = users.map((user) => ({
    id: user.id,
    name: user.name,
    role: user.email,
  }))

  const loadWorkflow = useCallback(async (id) => {
    if (!id) {
      setWorkflow(null)
      setProjectMeta(null)
      return
    }
    // GET /projects/{id} returns the project (needed for its planned_start/
    // end_date, to bound activity/requirement date pickers) and the workflow
    // payload together, so one request covers what used to be two calls.
    const response = await fetchCached(`/projects/${id}`, { params: { summary: 1 } })
    setProjectMeta(response.data?.data ?? null)
    setWorkflow(response.data?.workflow ?? null)
  }, [])

  const refreshMissingProjectDocs = useCallback(
    async (id) => {
      if (!id) {
        setMissingProjectDocTypes([])
        return
      }
      try {
        const response = await api.get(`/projects/${id}/documents`)
        const docs = unwrapList(response.data).filter((doc) => doc.is_current !== false)
        const requiredTypes = workflow?.required_document_types || REQUIRED_PROJECT_DOCUMENT_TYPES
        setMissingProjectDocTypes(getMissingRequiredDocumentTypes(docs, requiredTypes))
      } catch {
        setMissingProjectDocTypes([])
      }
    },
    [workflow],
  )

  useEffect(() => {
    if (formTarget !== null) {
      refreshMissingProjectDocs(projectId)
    }
  }, [formTarget, projectId, refreshMissingProjectDocs])

  const resolveEmbeddedProjectId = useCallback(() => {
    if (projectIdProp) return projectIdProp
    const fromRoute = Number(routeId)
    return Number.isFinite(fromRoute) && fromRoute > 0 ? fromRoute : null
  }, [projectIdProp, routeId])

  const loadProjectsAndUsers = useCallback(async () => {
    try {
      // Embedded workspace already knows the project — skip the heavy GET /projects list.
      if (embedded) {
        const embeddedId = resolveEmbeddedProjectId()
        if (embeddedId) {
          storeProjectId(embeddedId)
          setProjectId(embeddedId)
        }
        return
      }

      const projectsRes = await fetchProjectsCached()
      const projectList = unwrapList(projectsRes.data)
      setProjects(projectList)
      const fromRoute = Number(routeId)
      if (Number.isFinite(fromRoute) && fromRoute > 0) {
        storeProjectId(fromRoute)
        setProjectId(fromRoute)
        return
      }
      setProjectId((current) => {
        if (current && projectList.some((project) => project.id === current)) return current
        const first = projectList[0]?.id ?? null
        storeProjectId(first)
        return first
      })
    } catch (err) {
      setError(err.response?.data?.message || 'Could not load projects.')
    }
  }, [routeId, embedded, resolveEmbeddedProjectId])

  const loadActivities = useCallback(async (id) => {
    if (!id) {
      setActivities([])
      return
    }
    setLoading(true)
    setError('')
    try {
      const response = await fetchCached(`/projects/${id}/activities`)
      setActivities(unwrapList(response.data))
      await loadWorkflow(id)
    } catch (err) {
      setActivities([])
      setError(err.response?.data?.message || 'Could not load activities.')
    } finally {
      setLoading(false)
    }
  }, [loadWorkflow])

  const loadRequirements = useCallback(async (id) => {
    if (!id) {
      setRequirements([])
      return
    }
    setRequirementsLoading(true)
    try {
      const response = await fetchCached(`/projects/${id}/requirements`)
      setRequirements(unwrapList(response.data))
      await loadWorkflow(id)
    } catch {
      setRequirements([])
    } finally {
      setRequirementsLoading(false)
    }
  }, [loadWorkflow])

  useEffect(() => {
    if (active) loadProjectsAndUsers()
  }, [active, loadProjectsAndUsers])

  useEffect(() => {
    if (active && simplifiedPlannerView && workspaceSection !== 'activities') loadRequirements(projectId)
  }, [active, projectId, simplifiedPlannerView, workspaceSection, loadRequirements, revision])

  useEffect(() => {
    if (active && workspaceSection !== 'requirements') loadActivities(projectId)
  }, [active, projectId, workspaceSection, loadActivities, revision])

  useEffect(() => {
    if (!active || (!formTarget && !reviewTarget && !detailsTarget)) return
    let cancelled = false
    fetchCached('/users').then((response) => {
      if (!cancelled) setUsers(unwrapList(response.data))
    }).catch(() => message.error('Could not load team members.'))
    return () => { cancelled = true }
  }, [active, formTarget, reviewTarget, detailsTarget])

  useEffect(() => {
    if (!projectIdProp) return
    storeProjectId(projectIdProp)
    setProjectId((current) => (current === projectIdProp ? current : projectIdProp))
  }, [projectIdProp])

  useEffect(() => {
    const fromRoute = Number(routeId)
    if (Number.isFinite(fromRoute) && fromRoute > 0) {
      storeProjectId(fromRoute)
      setProjectId((current) => (current === fromRoute ? current : fromRoute))
    }
  }, [routeId])

  useEffect(() => {
    const queryProjectId = searchParams.get('projectId')
    if (!queryProjectId) return

    const parsed = Number(queryProjectId)
    if (!Number.isNaN(parsed)) {
      storeProjectId(parsed)
      setProjectId((current) => (current === parsed ? current : parsed))
    }
  }, [searchParams])

  const handleProjectChange = (id) => {
    storeProjectId(id)
    setProjectId(id)
  }

  const tryAutoSubmitPlan = async (id) => {
    await submitPlanForReview(id)
    await loadActivities(id)
    onProjectChanged?.()
  }

  // Uploads a batch of files in parallel instead of one request at a time —
  // with several attachments the sequential version could add many seconds
  // of pure network round-trip time before anything else could happen.
  const uploadDocuments = (files, extraFields) =>
    Promise.all(
      files.map((file) => {
        const formData = new FormData()
        formData.append('file', file)
        formData.append('project_id', projectId)
        Object.entries(extraFields).forEach(([key, value]) => formData.append(key, value))
        return api.post('/documents', formData)
      }),
    )

  const handleSaveForm = async (values) => {
    const { documents, projectDocuments, ...activityValues } = values
    const isEdit = formTarget?.id != null

    setSavingActivity(true)
    let activityRecord
    try {
      if (!isEdit) {
        const response = await api.post('/activities', { ...activityValues, project_id: projectId })
        activityRecord = unwrapItem(response.data)
        setActivities((prev) => [...prev, activityRecord])
      } else {
        const response = await api.put(`/activities/${formTarget.id}`, activityValues)
        activityRecord = unwrapItem(response.data)
        setActivities((prev) => prev.map((activity) => (activity.id === activityRecord.id ? activityRecord : activity)))
      }
    } catch (err) {
      message.error(err.response?.data?.message || 'Could not save activity.')
      setSavingActivity(false)
      return
    }

    // The activity itself is saved from here on — but keep the popup (and its
    // spinner) up through the document upload / plan-auto-submit follow-up
    // below instead of closing immediately, so the user sees one continuous
    // "working" state rather than the popup closing and a second, unrelated
    // notification landing a beat later with no visible cause.
    try {
      const uploadTasks = []
      if (documents?.length) {
        uploadTasks.push(uploadDocuments(documents, { activity_id: activityRecord.id }))
      }
      for (const [documentType, files] of Object.entries(normalizeProjectDocumentUploads(projectDocuments || {}))) {
        if (files.length) {
          uploadTasks.push(uploadDocuments(files, { document_type: documentType }))
        }
      }
      if (uploadTasks.length) {
        await Promise.all(uploadTasks)
      }

      const [docsRes] = await Promise.all([
        api.get(`/projects/${projectId}/documents`),
        loadWorkflow(projectId),
      ])
      const docs = unwrapList(docsRes.data).filter((doc) => doc.is_current !== false)
      const requiredTypes = workflow?.required_document_types || REQUIRED_PROJECT_DOCUMENT_TYPES
      const missing = getMissingRequiredDocumentTypes(docs, requiredTypes)

      let submittedPlan = false
      if (missing.length === 0 && ['draft', 'changes_requested'].includes(workflow?.plan_review_status || '')) {
        await tryAutoSubmitPlan(projectId)
        submittedPlan = true
      }

      setSavingActivity(false)
      setFormTarget(null)

      if (submittedPlan) {
        message.success(
          isEdit
            ? 'Changes saved and plan submitted for reviewer approval.'
            : 'Activity added and plan submitted for reviewer approval.',
        )
      } else {
        message.success(isEdit ? 'Changes saved' : 'Activity added')
        if (missing.length > 0) {
          Modal.warning({
            title: 'Missing required project documents',
            content: `Attach these project-level documents before the plan can be submitted: ${missing.join(', ')}.`,
          })
        }
      }

      onProjectChanged?.()
    } catch (err) {
      setSavingActivity(false)
      setFormTarget(null)
      message.error(err.response?.data?.message || 'Activity saved, but a follow-up step failed.')
    }
  }

  const loadReviewHistory = async (activityId) => {
    try {
      const response = await api.get(`/activities/${activityId}/progress`)
      setReviewHistory(unwrapList(response.data))
    } catch {
      setReviewHistory([])
    }
  }

  const openReview = async (activity) => {
    if (onActivityReview) {
      onActivityReview(activity)
      return
    }
    setReviewTarget(activity)
    await loadReviewHistory(activity.id)
  }

  const openEdit = (activity) => {
    setFormTarget(activity)
    setEditActivityDocs([])
    setEditActivityDocsLoading(true)
    api
      .get(`/projects/${projectId}/documents`, { params: { activity_id: activity.id } })
      .then((response) => setEditActivityDocs(unwrapList(response.data)))
      .catch(() => setEditActivityDocs([]))
      .finally(() => setEditActivityDocsLoading(false))
  }

  const viewActivityDocument = async (doc) => {
    try {
      const url = await fetchAuthorizedFileUrl(doc.id)
      window.open(url, '_blank', 'noopener')
    } catch {
      message.error('Could not open document.')
    }
  }

  const downloadActivityDocument = async (doc) => {
    try {
      const url = await fetchAuthorizedFileUrl(doc.id)
      const link = document.createElement('a')
      link.href = url
      link.download = doc.file_name || 'document'
      link.click()
      URL.revokeObjectURL(url)
    } catch {
      message.error('Could not download document.')
    }
  }

  // The activity's own "planned" fields become directly editable once the
  // whole plan has been returned — otherwise View opens a read-only popup
  // with an explicit Update action.
  const openDetails = (activity) => {
    // Only the Planner gets bounced straight into the edit form when the
    // plan's been returned — anyone else (the Reviewer especially) should
    // always land on the read-only details view, never the edit form.
    if (canAddActivity && workflow?.plan_review_status === 'changes_requested') {
      openEdit(activity)
      return
    }
    setDetailsTarget(activity)
    setDetailsDocs([])
    setDetailsDocsLoading(true)
    setActivityChangeComment('')
    api
      .get(`/projects/${projectId}/documents`, { params: { activity_id: activity.id } })
      .then((response) => setDetailsDocs(unwrapList(response.data)))
      .catch(() => setDetailsDocs([]))
      .finally(() => setDetailsDocsLoading(false))
  }

  const canReviewActivityChange = roleName === ROLES.PRV || roleName === ROLES.PAD

  // A pending activity means one of two different things on the backend:
  // either it's an edit/addition made *after* the plan was already approved
  // (plan_change_status = 'pending', reviewed per-activity), or the whole
  // plan is still on its very first, never-yet-approved submission (no
  // plan_change_status at all — reviewed as one plan, not per activity).
  // The planner-facing action is the same either way ("approve this"), so
  // the popup shows one Approve/Reject regardless — it just calls whichever
  // endpoint actually matches this activity's situation.
  const isPlanChangeActivity = Boolean(detailsTarget?.plan_change_status)
  const activityAwaitingApproval = Boolean(
    detailsTarget &&
      (detailsTarget.plan_change_status === 'pending' ||
        (!isPlanChangeActivity && workflow?.plan_review_status === 'pending_review')),
  )
  // Approved either individually (a post-approval addition/edit) or as part
  // of the whole plan's first approval (no plan_change_status of its own).
  const activityIsApproved = Boolean(
    detailsTarget &&
      (detailsTarget.plan_change_status === 'approved' ||
        (!isPlanChangeActivity && workflow?.plan_review_status === 'approved')),
  )
  const canMarkActivityComplete =
    canAddActivity && activityIsApproved && detailsTarget?.actual_start_date && !detailsTarget?.actual_end_date

  const approveActivityChange = async () => {
    if (!detailsTarget) return
    setActivityChangeSaving(true)
    try {
      if (isPlanChangeActivity) {
        const response = await api.post(`/activities/${detailsTarget.id}/plan-changes/approve`, {
          comment: activityChangeComment.trim() || undefined,
        })
        const updated = unwrapItem(response.data)
        setActivities((prev) => prev.map((item) => (item.id === updated.id ? updated : item)))
      } else {
        await api.post(`/projects/${projectId}/plan/review`, {
          decision: 'approved',
          comment: activityChangeComment.trim() || undefined,
        })
        await Promise.all([loadWorkflow(projectId), loadActivities(projectId)])
        onProjectChanged?.()
      }
      message.success('Plan approved')
      setDetailsTarget(null)
    } catch (err) {
      message.error(err.response?.data?.message || 'Could not approve the change.')
    } finally {
      setActivityChangeSaving(false)
    }
  }

  const rejectActivityChange = async () => {
    if (!detailsTarget) return
    if (!activityChangeComment.trim()) {
      message.error('Add a reason for the rejection')
      return
    }
    setActivityChangeSaving(true)
    try {
      if (isPlanChangeActivity) {
        const response = await api.post(`/activities/${detailsTarget.id}/plan-changes/reject`, {
          comment: activityChangeComment.trim(),
        })
        const updated = unwrapItem(response.data)
        setActivities((prev) => prev.map((item) => (item.id === updated.id ? updated : item)))
      } else {
        await api.post(`/projects/${projectId}/plan/review`, {
          decision: 'returned',
          comment: activityChangeComment.trim(),
        })
        await Promise.all([loadWorkflow(projectId), loadActivities(projectId)])
        onProjectChanged?.()
      }
      message.success('Plan rejected')
      setDetailsTarget(null)
    } catch (err) {
      message.error(err.response?.data?.message || 'Could not reject the change.')
    } finally {
      setActivityChangeSaving(false)
    }
  }

  const openUpdateFromDetails = () => {
    const activity = detailsTarget
    setDetailsTarget(null)
    if (activity) openEdit(activity)
  }

  const markActivityComplete = async () => {
    if (!detailsTarget) return
    try {
      const response = await api.put(`/activities/${detailsTarget.id}`, {
        actual_end_date: dayjs().format('YYYY-MM-DD'),
        status: 'completed',
        remark: 'Activity marked complete.',
      })
      const updated = unwrapItem(response.data)
      setActivities((prev) => prev.map((item) => (item.id === updated.id ? updated : item)))
      message.success('Activity marked complete')
      setDetailsTarget(null)
      onProjectChanged?.()
    } catch (err) {
      message.error(err.response?.data?.message || 'Could not mark activity complete.')
    }
  }

  const applyActivityUpdate = async (payload) => {
    const response = await api.put(`/activities/${reviewTarget.id}`, payload)
    const updated = unwrapItem(response.data)
    setActivities((prev) => prev.map((activity) => (activity.id === updated.id ? updated : activity)))
    setReviewTarget(updated)
    await loadReviewHistory(updated.id)
    return updated
  }

  const handleSaveActualStart = async (actual_start_date) => {
    try {
      const status = deriveStatus({ actual_start_date, actual_end_date: reviewTarget.actual_end_date })
      await applyActivityUpdate({ actual_start_date, status })
      message.success('Actual start saved')
    } catch (err) {
      message.error(err.response?.data?.message || 'Could not save actual start.')
    }
  }

  const handleMarkComplete = async () => {
    try {
      await applyActivityUpdate({
        actual_end_date: dayjs().format('YYYY-MM-DD'),
        status: 'completed',
        remark: 'Activity marked complete.',
      })
      message.success('Activity marked complete')
    } catch (err) {
      message.error(err.response?.data?.message || 'Could not mark activity complete.')
    }
  }

  const handleAddRemark = async (remark) => {
    try {
      await applyActivityUpdate({ remark })
      message.success('Remark added')
    } catch (err) {
      message.error(err.response?.data?.message || 'Could not add remark.')
    }
  }

  const handleApproveChange = async (activity) => {
    try {
      const response = await api.post(`/activities/${activity.id}/plan-changes/approve`)
      const updated = unwrapItem(response.data)
      setActivities((prev) => prev.map((item) => (item.id === updated.id ? updated : item)))
      setReviewTarget((prev) => (prev && prev.id === updated.id ? updated : prev))
      message.success('Plan change approved')
    } catch (err) {
      message.error(err.response?.data?.message || 'Could not approve change.')
    }
  }

  const handleRejectChange = async (activity) => {
    try {
      const response = await api.post(`/activities/${activity.id}/plan-changes/reject`, {
        comment: 'Plan change rejected by reviewer',
      })
      const updated = unwrapItem(response.data)
      setActivities((prev) => prev.map((item) => (item.id === updated.id ? updated : item)))
      setReviewTarget((prev) => (prev && prev.id === updated.id ? updated : prev))
      message.success('Plan change rejected')
    } catch (err) {
      message.error(err.response?.data?.message || 'Could not reject change.')
    }
  }

  const handleSubmitProgressReview = async (activity) => {
    try {
      const response = await api.post(`/activities/${activity.id}/progress-review/submit`)
      const updated = unwrapItem(response.data)
      setActivities((prev) => prev.map((item) => (item.id === updated.id ? updated : item)))
      setReviewTarget((prev) => (prev && prev.id === updated.id ? updated : prev))
      message.success('Submitted to the reviewer')
    } catch (err) {
      message.error(err.response?.data?.message || 'Could not submit for review.')
    }
  }

  const planStatus = workflow?.plan_review_status
  // Execution has actually started once either a Coordinator recommends
  // (SDMM/IDMM) or an Approver signs off directly (DICT, which skips
  // recommendation entirely) — recommended_at alone is never set on the
  // DICT path, so gating on it left the requirements matrix permanently
  // locked for that track even after sign-off.
  const executionUnderway = Boolean(workflow?.execution_started_at)
  const canAddRtm = executionUnderway && canAddActivity
  const canReviewRtm = !readOnlyBrowse && executionUnderway && (roleName === ROLES.PRV || roleName === ROLES.PAD)
  // Start / Record test result / Mark complete are the Planner's own
  // reporting of what actually happened — the Reviewer's role here is
  // Approve/Reject only, not doing the work.
  const canUpdateRtmProgress = executionUnderway && canAddActivity

  useEffect(() => {
    if (!registerActions || !active) return undefined
    registerActions({
      openAddActivity: () => setFormTarget(BLANK_ACTIVITY),
      openAddRequirement: () => setRtmModalOpen(true),
      canAddActivity: Boolean(canAddActivity && projectId && planStatus !== 'pending_review'),
      canAddRequirement: Boolean(canAddRtm),
    })
    return () => registerActions(null)
  }, [active, registerActions, canAddActivity, canAddRtm, projectId, planStatus])

  const openRtmView = (requirement) => {
    setRtmViewTarget(requirement)
    setRtmViewDocs([])
    setRtmViewDocsLoading(true)
    api
      .get(`/projects/${projectId}/documents`, { params: { requirement_id: requirement.id } })
      .then((response) => setRtmViewDocs(unwrapList(response.data)))
      .catch(() => setRtmViewDocs([]))
      .finally(() => setRtmViewDocsLoading(false))
  }

  const reviewRequirement = async (id, review_decision, comment) => {
    setRtmSaving(true)
    try {
      await api.patch(`/requirements/${id}/review`, { review_decision, comment })
      message.success(review_decision === 'approved' ? 'Requirement approved' : 'Requirement rejected')
      setRtmViewTarget(null)
      setRtmRejecting(false)
      setRtmRejectComment('')
      await loadRequirements(projectId)
      onProjectChanged?.()
    } catch (err) {
      message.error(err.response?.data?.message || 'Could not save review decision.')
    } finally {
      setRtmSaving(false)
    }
  }

  const confirmRejectRequirement = () => {
    if (!rtmRejectComment.trim()) {
      message.error('Add a reason for the rejection')
      return
    }
    reviewRequirement(rtmViewTarget.id, 'rejected', rtmRejectComment.trim())
  }

  const openRtmEdit = (requirement) => {
    setRtmEditTarget(requirement)
    setRtmEditCode(requirement.requirement_code || '')
    setRtmEditDescription(requirement.description || '')
    setRtmViewTarget(null)
  }

  const submitRtmEdit = async () => {
    if (!rtmEditCode.trim() || !rtmEditDescription.trim()) {
      message.error('Code and description are both required')
      return
    }
    setRtmEditSaving(true)
    try {
      await api.put(`/requirements/${rtmEditTarget.id}`, {
        requirement_code: rtmEditCode.trim(),
        description: rtmEditDescription.trim(),
      })
      message.success('Requirement updated and resubmitted for review')
      setRtmEditTarget(null)
      await loadRequirements(projectId)
    } catch (err) {
      message.error(err.response?.data?.message || 'Could not update the requirement.')
    } finally {
      setRtmEditSaving(false)
    }
  }

  const startRequirement = async ({ actual_start_date, remark }) => {
    try {
      const response = await api.patch(`/requirements/${rtmProgressTarget.id}/status`, {
        actual_start_date,
        remarks: remark,
      })
      message.success('Requirement started')
      setRtmProgressTarget(null)
      await loadRequirements(projectId)
      openRtmView(unwrapItem(response.data))
    } catch (err) {
      message.error(err.response?.data?.message || 'Could not save.')
    }
  }

  const markRequirementComplete = async (requirement) => {
    try {
      await api.patch(`/requirements/${requirement.id}/status`, {
        actual_end_date: dayjs().format('YYYY-MM-DD'),
        remarks: 'Requirement marked complete.',
      })
      message.success('Requirement marked complete')
      setRtmViewTarget(null)
      await loadRequirements(projectId)
      onProjectChanged?.()
    } catch (err) {
      message.error(err.response?.data?.message || 'Could not mark complete.')
    }
  }

  const saveRequirementTestResult = async ({ test_result, test_comments }) => {
    try {
      const response = await api.patch(`/requirements/${rtmTestTarget.id}/status`, {
        implementation_status: rtmTestTarget.implementation_status || 'Pending',
        test_result: uiTestResultToApi(test_result),
        remarks: test_comments,
      })
      message.success('Test result saved')
      setRtmTestTarget(null)
      await loadRequirements(projectId)
      openRtmView(unwrapItem(response.data))
    } catch (err) {
      message.error(err.response?.data?.message || 'Could not save test result.')
    }
  }

  const visibleActivities = activities.filter((activity) => {
    const phaseFilter = filteredInfo.phase
    if (phaseFilter?.length && !phaseFilter.includes(activity.phase)) return false
    const statusFilter = filteredInfo.status
    if (statusFilter?.length && !statusFilter.includes(deriveStatus(activity))) return false
    return true
  })

  const showActivities = workspaceSection !== 'requirements'
  const showRequirements =
    workspaceSection === 'requirements' ||
    (workspaceSection == null && simplifiedPlannerView && (canAddRtm || requirements.length > 0))
  const showToolbar = workspaceSection !== 'requirements' && !hideInlineAddActions
  const showInlineAddRequirement = canAddRtm && !hideInlineAddActions

  const toolbar = (
    <Space wrap size="middle" className="ms-auto justify-end">
      {!embedded && (
        <ProjectPicker projects={projects} value={projectId} onChange={handleProjectChange} />
      )}
      {canAddActivity && showToolbar && (
        <PreventMutation fallback={null}>
          <button
            type="button"
            disabled={!projectId || planStatus === 'pending_review'}
            onClick={() => setFormTarget(BLANK_ACTIVITY)}
            className="inline-flex items-center gap-2 rounded-lg text-sm font-medium text-white transition-colors disabled:opacity-50"
            style={{ background: '#7A0C22', height: 42, padding: '0 22px' }}
          >
            <Plus size={16} />
            Add activity
          </button>
        </PreventMutation>
      )}
    </Space>
  )

  const currentProject = projectMeta || projects.find((project) => project.id === projectId)

  return (
    <div className="flex flex-col gap-3">
      {showToolbar &&
        (toolbarContainer ? (
          createPortal(toolbar, toolbarContainer)
        ) : (
          <div className="flex w-full flex-wrap items-center justify-end gap-3">{toolbar}</div>
        ))}

      {!hideWorkflowBar && (
        <WorkflowBar projectId={projectId} workflow={workflow} hideReapprovalNotice={hideReapprovalNotice} />
      )}

      {error && <Alert type="error" showIcon message={error} />}

      {showActivities && (
        <Spin spinning={loading}>
          <ActivitiesTable
            activities={activities}
            visibleActivities={visibleActivities}
            filteredInfo={filteredInfo}
            onTableChange={(_pagination, filters) => setFilteredInfo(filters)}
            onReview={openReview}
            onEdit={!simplifiedPlannerView && canAddActivity ? openEdit : null}
            onView={simplifiedPlannerView ? openDetails : null}
            editDisabled={!projectId || planStatus === 'pending_review'}
            hideExpectedDeliverable={hideExpectedDeliverable}
            useApprovalStatus={simplifiedPlannerView || hideExpectedDeliverable}
            planReviewStatus={workflow?.plan_review_status}
            people={people}
            forceShowActions={!readOnlyBrowse && Boolean(onActivityReview)}
            hideActions={readOnlyBrowse}
            shouldShowReview={shouldShowActivityReview}
          />
        </Spin>
      )}

      {showRequirements && (
        <div className={workspaceSection === 'requirements' ? undefined : 'mt-2'}>
          {workspaceSection !== 'requirements' && (
            <div className="mb-2" style={{ color: '#800000', fontWeight: 800 }}>
              RTM Requirements
            </div>
          )}

          {requirementsLoading ? (
            <Spin size="small" />
          ) : (
            <Table
              className="mb-3"
              size="small"
              rowKey="id"
              pagination={false}
              dataSource={requirements}
              locale={{ emptyText: 'No requirements added yet.' }}
              columns={[
                  { title: 'SN', width: 56, align: 'center', render: (_, __, index) => index + 1 },
                  {
                    title: 'Code',
                    dataIndex: 'requirement_code',
                    width: 100,
                    ellipsis: true,
                    onHeaderCell: () => ({ style: { whiteSpace: 'nowrap' } }),
                  },
                  {
                    title: 'Status',
                    dataIndex: 'implementation_status',
                    width: 130,
                    onHeaderCell: () => ({ style: { whiteSpace: 'nowrap' } }),
                    render: (value) => <Tag style={{ fontSize: 14, padding: '2px 10px' }}>{value || 'Pending'}</Tag>,
                  },
                  {
                    title: 'Score',
                    dataIndex: 'score_percent',
                    width: 90,
                    align: 'center',
                    onHeaderCell: () => ({ style: { whiteSpace: 'nowrap' } }),
                    render: (value) => `${value ?? 0}%`,
                  },
                  {
                    title: 'Review Decision',
                    dataIndex: 'review_decision',
                    width: 140,
                    onHeaderCell: () => ({ style: { whiteSpace: 'nowrap' } }),
                    render: (value) =>
                      value ? (
                        <Tag
                          color={value === 'approved' ? 'green' : value === 'rejected' ? 'red' : 'gold'}
                          style={{ fontSize: 14, padding: '2px 10px' }}
                        >
                          {value.replace('_', ' ')}
                        </Tag>
                      ) : (
                        <Tag style={{ fontSize: 14, padding: '2px 10px' }}>Not reviewed</Tag>
                      ),
                  },
                  ...(!readOnlyBrowse
                    ? [
                        {
                          title: 'Action',
                          width: 90,
                          onHeaderCell: () => ({ style: { whiteSpace: 'nowrap' } }),
                          render: (_, record) => (
                            <Button
                              type="primary"
                              icon={<EyeOutlined />}
                              style={{ backgroundColor: '#800000', borderColor: '#800000' }}
                              onClick={() => openRtmView(record)}
                            >
                              View
                            </Button>
                          ),
                        },
                      ]
                    : []),
                ]}
            />
          )}

          {showInlineAddRequirement && (
            <div className="flex justify-end">
              <Button
                type="primary"
                icon={<PlusOutlined />}
                style={{ backgroundColor: '#7A0C22', borderColor: '#7A0C22' }}
                onClick={() => setRtmModalOpen(true)}
              >
                Add requirement
              </Button>
            </div>
          )}
        </div>
      )}

      <ActivityFormModal
        open={formTarget !== null}
        activity={formTarget}
        people={people}
        requiredProjectDocTypes={missingProjectDocTypes}
        activityDocuments={editActivityDocs}
        activityDocumentsLoading={editActivityDocsLoading}
        saving={savingActivity}
        returnedComment={
          workflow?.plan_review_status === 'changes_requested' ? workflow?.plan_review_comment : null
        }
        projectPlannedStart={currentProject?.planned_start_date}
        projectPlannedEnd={currentProject?.planned_end_date}
        onViewDocument={viewActivityDocument}
        onCancel={() => setFormTarget(null)}
        onSave={handleSaveForm}
      />
      <ActivityReviewDrawer
        open={reviewTarget !== null}
        activity={reviewTarget}
        people={people}
        history={reviewHistory}
        onClose={() => {
          setReviewTarget(null)
          setReviewHistory([])
        }}
        onSaveActualStart={handleSaveActualStart}
        onMarkComplete={handleMarkComplete}
        onAddRemark={handleAddRemark}
        onApproveChange={handleApproveChange}
        onRejectChange={handleRejectChange}
        onSubmitForReview={handleSubmitProgressReview}
      />
      <ActivityDocumentsModal
        open={docsTarget !== null}
        activity={docsTarget}
        projectId={projectId}
        onCancel={() => setDocsTarget(null)}
      />
      <ActivityDetailsModal
        open={detailsTarget !== null}
        activity={detailsTarget}
        people={people}
        documents={detailsDocs}
        documentsLoading={detailsDocsLoading}
        onClose={() => {
          setDetailsTarget(null)
          setActivityChangeComment('')
        }}
        onUpdate={canAddActivity ? openUpdateFromDetails : null}
        onViewDocument={viewActivityDocument}
        onDownloadDocument={downloadActivityDocument}
        canReviewChange={canReviewActivityChange && activityAwaitingApproval}
        changeComment={activityChangeComment}
        onChangeCommentChange={setActivityChangeComment}
        onApproveChange={approveActivityChange}
        onRejectChange={rejectActivityChange}
        changeSaving={activityChangeSaving}
        canMarkComplete={canMarkActivityComplete}
        onMarkComplete={markActivityComplete}
      />
      <AddRtmModal
        open={rtmModalOpen}
        projectId={projectId}
        onClose={() => setRtmModalOpen(false)}
        onAdded={() => {
          loadRequirements(projectId)
          onProjectChanged?.()
        }}
      />

      <Modal
        title={<span style={{ color: '#800000', fontWeight: 700 }}>Requirement details</span>}
        open={rtmViewTarget !== null}
        onCancel={() => {
          setRtmViewTarget(null)
          setRtmRejecting(false)
          setRtmRejectComment('')
        }}
        destroyOnHidden
        width={MODAL_WIDTH.xl}
        className="pms-modal-xl"
        footer={
          <div style={{ display: 'flex', flexWrap: 'wrap', justifyContent: 'flex-end', gap: 8 }}>
            {rtmRejecting ? (
              <>
                <Button danger loading={rtmSaving} onClick={confirmRejectRequirement}>
                  Confirm reject
                </Button>
                <Button
                  onClick={() => {
                    setRtmRejecting(false)
                    setRtmRejectComment('')
                  }}
                >
                  Cancel
                </Button>
              </>
            ) : (
              <>
                {canReviewRtm && !rtmViewTarget?.review_decision && (
                  <>
                    <Button
                      type="primary"
                      style={{ backgroundColor: '#7A0C22', borderColor: '#7A0C22' }}
                      loading={rtmSaving}
                      onClick={() => rtmViewTarget && reviewRequirement(rtmViewTarget.id, 'approved')}
                    >
                      Approve
                    </Button>
                    <Button danger onClick={() => setRtmRejecting(true)}>
                      Reject
                    </Button>
                  </>
                )}
                {canAddRtm && rtmViewTarget?.review_decision === 'rejected' && (
                  <Button
                    type="primary"
                    style={{ backgroundColor: '#7A0C22', borderColor: '#7A0C22' }}
                    onClick={() => openRtmEdit(rtmViewTarget)}
                  >
                    Edit & resubmit
                  </Button>
                )}
                {canUpdateRtmProgress &&
                  rtmViewTarget?.review_decision === 'approved' &&
                  !rtmViewTarget?.actual_start_date && (
                    <Button
                      onClick={() => {
                        setRtmProgressTarget(rtmViewTarget)
                        setRtmViewTarget(null)
                      }}
                    >
                      Start
                    </Button>
                  )}
                {canUpdateRtmProgress &&
                  rtmViewTarget?.review_decision === 'approved' &&
                  rtmViewTarget?.actual_start_date &&
                  !rtmViewTarget?.test_result && (
                    <Button
                      onClick={() => {
                        setRtmTestTarget(rtmViewTarget)
                        setRtmViewTarget(null)
                      }}
                    >
                      Record test result
                    </Button>
                  )}
                {canUpdateRtmProgress &&
                  rtmViewTarget?.review_decision === 'approved' &&
                  rtmViewTarget?.test_result &&
                  !rtmViewTarget?.actual_end_date && (
                    <Popconfirm
                      title="Mark this requirement complete?"
                      description={`This records today's date (${dayjs().format('MMM D, YYYY')}) as the actual end and cannot be undone.`}
                      okText="Mark complete"
                      onConfirm={() => markRequirementComplete(rtmViewTarget)}
                    >
                      <Button>Mark complete</Button>
                    </Popconfirm>
                  )}
                <Button
                  onClick={() => {
                    setRtmViewTarget(null)
                    setRtmRejecting(false)
                    setRtmRejectComment('')
                  }}
                >
                  Close
                </Button>
              </>
            )}
          </div>
        }
      >
        {rtmViewTarget && (
          <>
            <Descriptions column={1} bordered size="small">
              <Descriptions.Item label="Code">{rtmViewTarget.requirement_code}</Descriptions.Item>
              <Descriptions.Item label="Description">{rtmViewTarget.description}</Descriptions.Item>
              <Descriptions.Item label="Status">
                <Tag>{rtmViewTarget.implementation_status || 'Pending'}</Tag>
              </Descriptions.Item>
              <Descriptions.Item label="Actual start">{formatDate(rtmViewTarget.actual_start_date)}</Descriptions.Item>
              <Descriptions.Item label="Actual end">{formatDate(rtmViewTarget.actual_end_date)}</Descriptions.Item>
              <Descriptions.Item label="Review decision">
                {rtmViewTarget.review_decision ? (
                  <Tag
                    color={
                      rtmViewTarget.review_decision === 'approved'
                        ? 'green'
                        : rtmViewTarget.review_decision === 'rejected'
                          ? 'red'
                          : 'gold'
                    }
                  >
                    {rtmViewTarget.review_decision.replace('_', ' ')}
                  </Tag>
                ) : (
                  <Tag>Not reviewed</Tag>
                )}
              </Descriptions.Item>
              <Descriptions.Item label="Remarks">{rtmViewTarget.remarks || '—'}</Descriptions.Item>
            </Descriptions>

            {rtmViewTarget.review_decision === 'rejected' && !rtmRejecting && (
              <Alert
                className="mt-3"
                type="error"
                showIcon
                message="Reason for rejection"
                description={rtmViewTarget.review_comment || 'No reason was given.'}
              />
            )}

            {rtmRejecting && (
              <div className="mt-3">
                <div className="mb-1 text-sm font-medium">Reason for rejection</div>
                <Input.TextArea
                  rows={3}
                  autoFocus
                  value={rtmRejectComment}
                  onChange={(event) => setRtmRejectComment(event.target.value)}
                  placeholder="Explain what needs to change so the planner can act on it"
                />
              </div>
            )}

            <div className="mt-4">
              <div className="mb-2 text-sm font-semibold">Documents</div>
              <Spin spinning={rtmViewDocsLoading}>
                <Table
                  rowKey="id"
                  size="small"
                  dataSource={rtmViewDocs}
                  pagination={false}
                  locale={{ emptyText: 'No documents attached to this requirement.' }}
                  columns={[
                    { title: 'File', dataIndex: 'file_name' },
                    { title: 'Document Type', dataIndex: 'document_type', render: (value) => value || 'Document' },
                    {
                      title: 'Action',
                      width: 160,
                      render: (_, doc) => (
                        <Space size="small">
                          <Button size="small" icon={<EyeOutlined />} onClick={() => viewActivityDocument(doc)}>
                            View
                          </Button>
                          <Button size="small" icon={<DownloadOutlined />} onClick={() => downloadActivityDocument(doc)} />
                        </Space>
                      ),
                    },
                  ]}
                />
              </Spin>
            </div>
          </>
        )}
      </Modal>

      <RequirementProgressModal
        open={rtmProgressTarget !== null}
        requirement={rtmProgressTarget}
        plannedStartDate={currentProject?.planned_start_date}
        plannedEndDate={currentProject?.planned_end_date}
        onCancel={() => setRtmProgressTarget(null)}
        onSave={startRequirement}
      />

      <TestScoreModal
        open={rtmTestTarget !== null}
        requirement={
          rtmTestTarget ? { ...rtmTestTarget, test_result: apiTestResultToUi(rtmTestTarget.test_result) } : null
        }
        onCancel={() => setRtmTestTarget(null)}
        onSave={saveRequirementTestResult}
      />

      <Modal
        title={<span style={{ color: '#800000', fontWeight: 700 }}>Edit requirement</span>}
        open={rtmEditTarget !== null}
        onCancel={() => setRtmEditTarget(null)}
        destroyOnHidden
        width={MODAL_WIDTH.md}
        className="pms-modal-md"
        footer={
          <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8 }}>
            <Button
              type="primary"
              style={{ backgroundColor: '#7A0C22', borderColor: '#7A0C22' }}
              loading={rtmEditSaving}
              onClick={submitRtmEdit}
            >
              Save and resubmit
            </Button>
            <Button onClick={() => setRtmEditTarget(null)}>Cancel</Button>
          </div>
        }
      >
        <p className="mb-3 text-sm text-gray-600">
          Fixing and saving sends this requirement back to the reviewer as not-yet-reviewed.
        </p>
        <div className="flex flex-col gap-3">
          <div>
            <div className="mb-1 text-sm font-medium">Requirement code</div>
            <Input value={rtmEditCode} onChange={(event) => setRtmEditCode(event.target.value)} placeholder="e.g. REQ-001" />
          </div>
          <div>
            <div className="mb-1 text-sm font-medium">Description</div>
            <Input.TextArea
              rows={3}
              value={rtmEditDescription}
              onChange={(event) => setRtmEditDescription(event.target.value)}
              placeholder="Functional or technical requirement"
            />
          </div>
        </div>
      </Modal>
    </div>
  )
}

export default ImplementationPlanPage
