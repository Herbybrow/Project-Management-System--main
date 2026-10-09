import { lazy, Suspense, useEffect, useMemo, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { Card, Col, Row, Spin, Statistic } from 'antd'
import api from '../lib/axios'
import { unwrapItem } from '../lib/apiHelpers'
import { useAuth } from '../context/AuthContext'
import { ROLES } from '../utility/Config.jsx'
import { DASHBOARD_CARD_STYLE } from '../components/dashboard/shared/chartConstants'

const ROLE_DASHBOARDS = {
  [ROLES.PAD]: lazy(() => import('../components/dashboard/AdministratorDashboard')),
  [ROLES.PRV]: lazy(() => import('../components/dashboard/ReviewerDashboard')),
  [ROLES.PVO]: lazy(() => import('../components/dashboard/ViewOnlyDashboard')),
  [ROLES.IS]: lazy(() => import('../components/dashboard/IctSupportDashboard')),
  [ROLES.PPL]: lazy(() => import('../components/dashboard/PlannerDashboard')),
  [ROLES.PCO]: lazy(() => import('../components/dashboard/CoordinatorDashboard')),
  [ROLES.PAP]: lazy(() => import('../components/dashboard/ApproverDashboard')),
}

const METRIC_LABELS = {
  new_registrations: 'New registrations',
  plans_pending_review: 'Plans pending review',
  awaiting_recommendation: 'Awaiting recommendation',
  awaiting_execution_sign_off: 'Awaiting execution sign-off',
  in_execution: 'In execution',
  closed: 'Closed projects',
  assigned_projects: 'Assigned projects',
  active_activities: 'Activities',
  pending_matrix_items: 'Pending matrix items',
  overdue_activities: 'Overdue activities',
}

function DashboardPage() {
  const navigate = useNavigate()
  const { activeRole } = useAuth()
  const isAdministrator = activeRole?.name === ROLES.PAD
  const isReviewer = activeRole?.name === ROLES.PRV
  const isViewOnly = activeRole?.name === ROLES.PVO
  const isIctSupport = activeRole?.name === ROLES.IS
  const isPlanner = activeRole?.name === ROLES.PPL
  const isCoordinator = activeRole?.name === ROLES.PCO
  const isApprover = activeRole?.name === ROLES.PAP
  const hasDedicatedDashboard =
    isAdministrator || isReviewer || isPlanner || isCoordinator || isApprover || isViewOnly || isIctSupport
  const [payload, setPayload] = useState(null)

  // Dedicated role dashboards fetch their own /dashboard payload — skip the
  // parent call so we don't double (or StrictMode-quadruple) the heavy query.
  useEffect(() => {
    if (hasDedicatedDashboard) {
      setPayload(null)
      return
    }

    let cancelled = false
    api
      .get('/dashboard', { params: { role: activeRole?.name } })
      .then((response) => {
        if (!cancelled) setPayload(unwrapItem(response.data))
      })
      .catch(() => {
        if (!cancelled) setPayload({ counts: {}, pending_actions: [] })
      })
    return () => {
      cancelled = true
    }
  }, [activeRole?.name, hasDedicatedDashboard])

  const metrics = useMemo(() => {
    const counts = payload?.counts || {}
    return Object.entries(METRIC_LABELS)
      .filter(([key]) => counts[key] != null)
      .map(([key, label]) => ({ key, label, value: counts[key] }))
  }, [payload])

  const RoleDashboard = ROLE_DASHBOARDS[activeRole?.name]
  if (RoleDashboard) {
    return <Suspense fallback={<Spin size="large" />}><RoleDashboard /></Suspense>
  }

  return (
    <div className="flex flex-col gap-3">
      <Row gutter={[12, 12]}>
        {metrics.map((metric) => (
          <Col xs={24} sm={12} lg={8} key={metric.key}>
            <Card className="page-shell-card" style={{ ...DASHBOARD_CARD_STYLE, marginTop: 0 }}>
              <Statistic title={metric.label} value={metric.value} />
            </Card>
          </Col>
        ))}
      </Row>

      {(payload?.pending_actions || []).length > 0 && (
        <Card className="page-shell-card" style={{ marginTop: 0 }} title="Pending actions">
          <ul className="m-0 list-disc space-y-2 pl-5">
            {payload.pending_actions.map((action) => (
              <li key={action.path}>
                <button
                  type="button"
                  className="text-[#650018] hover:opacity-80"
                  onClick={() => navigate(action.path)}
                >
                  {action.label}
                </button>
              </li>
            ))}
          </ul>
        </Card>
      )}
    </div>
  )
}

export default DashboardPage
