import { lazy, Suspense, useCallback, useEffect, useState } from 'react'
import { Spin } from 'antd'
import { FileText, Activity, ListChecks } from 'lucide-react'
import { storeProjectId } from '../../lib/apiHelpers'
import { formatDate } from '../../lib/dates'
import './ProjectDetailsView.css'

const DocumentList = lazy(() => import('../documents/DocumentList'))
const ImplementationPlanPage = lazy(() => import('../../pages/ImplementationPlanPage'))

const TABS = [
  { key: 'activity', label: 'Activity', Icon: Activity },
  { key: 'requirements', label: 'Requirements', Icon: ListChecks },
  { key: 'documents', label: 'Documents', Icon: FileText },
]

function statusDotClass(status) {
  const value = String(status || '').toLowerCase()
  if (value.includes('closed') || value.includes('completed')) return 'is-closed'
  if (value.includes('reject') || value.includes('return')) return 'is-danger'
  if (value.includes('approv') || value.includes('execution')) return 'is-success'
  return 'is-active'
}

/**
 * Redesigned project Details workspace — header, summary strip, and
 * Activity / Requirements / Documents tabs.
 */
export default function ProjectDetailsView({
  project,
  onProjectChanged,
  onActivityReview,
  shouldShowActivityReview,
  readOnlyBrowse = false,
  canReassign = false,
  onReassign,
  alerts = null,
  initiationPanel = null,
  onFooterActionChange = null,
}) {
  const projectId = project?.id
  const [activeTab, setActiveTab] = useState('activity')
  const [visitedTabs, setVisitedTabs] = useState(['activity'])
  const counts = {
    documents: project?.documents_count,
    activity: project?.implementation_activities_count,
    requirements: project?.requirements_count,
  }
  const [sectionActions, setSectionActions] = useState(null)

  const handleRegisterActions = useCallback((actions) => {
    setSectionActions(actions)
  }, [])

  useEffect(() => {
    if (projectId) storeProjectId(projectId)
  }, [projectId])

  useEffect(() => {
    if (!onFooterActionChange) return undefined

    if (readOnlyBrowse || initiationPanel) {
      onFooterActionChange(null)
      return undefined
    }

    if (activeTab === 'activity' && sectionActions?.canAddActivity) {
      onFooterActionChange({
        label: '+ Add activity',
        onClick: () => sectionActions.openAddActivity?.(),
      })
    } else if (activeTab === 'requirements' && sectionActions?.canAddRequirement) {
      onFooterActionChange({
        label: '+ Add requirement',
        onClick: () => sectionActions.openAddRequirement?.(),
      })
    } else {
      onFooterActionChange(null)
    }

    return () => onFooterActionChange(null)
  }, [activeTab, sectionActions, readOnlyBrowse, initiationPanel, onFooterActionChange])

  const selectTab = (key) => {
    setActiveTab(key)
    setVisitedTabs((previous) => previous.includes(key) ? previous : [...previous, key])
  }

  if (!project) return null

  const dateLabel =
    project.planned_start_date || project.planned_end_date
      ? `${formatDate(project.planned_start_date)} – ${formatDate(project.planned_end_date)}`
      : '—'

  const statusLabel = project.status || '—'

  return (
    <div className="pms-detail">
      <header className="pms-detail__header">
        <div className="pms-detail__title-block">
          <div className="pms-detail__eyebrow">
            <span>Project details</span>
            <span className="pms-detail__eyebrow-line" aria-hidden="true" />
          </div>
          <h2 className="pms-detail__name">{project.name || 'Untitled project'}</h2>
        </div>
        <div className={`pms-detail__status-pill ${statusDotClass(statusLabel)}`}>
          <span className="pms-detail__status-dot" aria-hidden="true" />
          {statusLabel}
        </div>
      </header>

      <div className="pms-detail__summary">
        <div className="pms-detail__summary-item">
          <span className="pms-detail__summary-label">Category</span>
          <span className="pms-detail__summary-value">{project.category || '—'}</span>
        </div>
        <div className="pms-detail__summary-item">
          <span className="pms-detail__summary-label">Type</span>
          <span className="pms-detail__summary-value">{project.project_type || '—'}</span>
        </div>
        <div className="pms-detail__summary-item">
          <span className="pms-detail__summary-label">Date</span>
          <span className="pms-detail__summary-value">{dateLabel}</span>
        </div>
        <div className="pms-detail__summary-item">
          <span className="pms-detail__summary-label">Planner</span>
          <span className="pms-detail__summary-value">
            {project.planner?.name || 'Unassigned'}
            {canReassign && onReassign && (
              <button type="button" className="pms-detail__reassign" onClick={() => onReassign(project)}>
                Reassign
              </button>
            )}
          </span>
        </div>
        <div className="pms-detail__summary-item">
          <span className="pms-detail__summary-label">Status</span>
          <span className="pms-detail__summary-value">{statusLabel}</span>
        </div>
      </div>

      {alerts}

      {initiationPanel ? (
        <div className="pms-detail__initiation">{initiationPanel}</div>
      ) : (
        <>
          <nav className="pms-detail__tabs" aria-label="Project sections">
            {TABS.map(({ key, label, Icon }) => {
              const active = activeTab === key
              return (
                <button
                  key={key}
                  type="button"
                  className={`pms-detail__tab${active ? ' is-active' : ''}`}
                  onClick={() => selectTab(key)}
                >
                  <Icon size={16} strokeWidth={2} aria-hidden="true" />
                  <span>{label}</span>
                  <span className="pms-detail__tab-count">{counts[key] ?? '…'}</span>
                </button>
              )
            })}
          </nav>

          <div className="pms-detail__panel">
            {visitedTabs.includes('documents') && (
              <div hidden={activeTab !== 'documents'}>
                <Suspense fallback={<Spin size="small" />}>
                  <DocumentList embedded projectId={projectId} compact detailStyle active={activeTab === 'documents'} />
                </Suspense>
              </div>
            )}
            {visitedTabs.includes('activity') && (
              <div hidden={activeTab !== 'activity'}><Suspense fallback={<Spin size="small" />}>
              <ImplementationPlanPage
                active={activeTab === 'activity'}
                embedded
                projectId={projectId}
                onActivityReview={readOnlyBrowse ? null : onActivityReview}
                onProjectChanged={onProjectChanged}
                shouldShowActivityReview={readOnlyBrowse ? null : shouldShowActivityReview}
                simplifiedPlannerView
                hideExpectedDeliverable
                hideReapprovalNotice
                hideWorkflowBar
                hideInlineAddActions
                registerActions={handleRegisterActions}
                readOnlyBrowse={readOnlyBrowse}
                workspaceSection="activities"
              />
              </Suspense></div>
            )}
            {visitedTabs.includes('requirements') && (
              <div hidden={activeTab !== 'requirements'}><Suspense fallback={<Spin size="small" />}>
              <ImplementationPlanPage
                active={activeTab === 'requirements'}
                embedded
                projectId={projectId}
                onProjectChanged={onProjectChanged}
                simplifiedPlannerView
                hideExpectedDeliverable
                hideReapprovalNotice
                hideWorkflowBar
                hideInlineAddActions
                registerActions={handleRegisterActions}
                readOnlyBrowse={readOnlyBrowse}
                workspaceSection="requirements"
              />
              </Suspense></div>
            )}
          </div>
        </>
      )}
    </div>
  )
}
