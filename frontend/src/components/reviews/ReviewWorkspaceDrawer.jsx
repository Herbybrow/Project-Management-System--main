import { useCallback, useEffect, useState } from 'react'
import {
  Alert,
  Button,
  Form,
  Input,
  Modal,
  Space,
  Spin,
  Typography,
  message,
} from 'antd'
import dayjs from 'dayjs'
import RoleGuard from '../common/RoleGuard'
import ClosurePanel from '../projects/ClosurePanel'
import DataTable from '../common/DataTable'
import api from '../../lib/axios'
import { unwrapList } from '../../lib/apiHelpers'
import { MODAL_WIDTH } from '../../lib/modalSizes'
import { ROLES } from '../../utility/Config.jsx'
import { useAuth } from '../../context/AuthContext'
import { formatRoleLabel } from '../../layouts/nav.js'

const { Title, Text } = Typography

const MAROON = '#962c30'
const PRIMARY_BTN = {
  backgroundColor: MAROON,
  borderColor: MAROON,
  color: '#fff',
  fontWeight: 600,
}

function hasPermission(user, code) {
  return (user?.permissions || []).includes(code)
}

function Section({ title, children }) {
  return (
    <section className="mb-6">
      <Title level={5} className="!mb-2">
        {title}
      </Title>
      {children}
    </section>
  )
}

/** Shown in place of the action button when the signed-in role isn't the one that
 * owns this step, so a stuck-looking empty section reads as "waiting on someone
 * else" instead of "broken". */
function AwaitingRoleNotice({ activeRoleName, allow }) {
  if (allow.includes(activeRoleName)) return null
  const roleNames = allow.filter((role) => role !== ROLES.PAD).map(formatRoleLabel)
  return (
    <Alert
      type="info"
      showIcon
      message={`Waiting on ${roleNames.join(' or ')}`}
      description="You don't hold that role on this account. Switch to it (or have someone who does) to take this action."
    />
  )
}

/**
 * Flat review content — no nested tab strips. Only sections with work are shown.
 */
export function ReviewWorkspacePanel({ projectId, projectName, onChanged }) {
  const { user, activeRole } = useAuth()
  const activeRoleName = activeRole?.name ?? user?.role ?? null
  const [loading, setLoading] = useState(false)
  const [workflow, setWorkflow] = useState(null)
  const [activities, setActivities] = useState([])
  const [closure, setClosure] = useState(null)
  const [commentForm] = Form.useForm()
  const [modal, setModal] = useState(null)
  const [saving, setSaving] = useState(false)

  const load = useCallback(async () => {
    if (!projectId) return
    setLoading(true)
    try {
      const [workflowRes, activitiesRes, closureRes] = await Promise.all([
        api.get(`/projects/${projectId}/workflow`),
        api.get(`/projects/${projectId}/activities`).catch(() => ({ data: { data: [] } })),
        api.get(`/projects/${projectId}/closure-readiness`).catch(() => ({ data: { data: null } })),
      ])
      setWorkflow(workflowRes.data?.data)
      setActivities(unwrapList(activitiesRes.data))
      setClosure(closureRes.data?.data || null)
    } catch (err) {
      message.error(err.response?.data?.message || 'Could not load review items.')
    } finally {
      setLoading(false)
    }
  }, [projectId])

  useEffect(() => {
    load()
  }, [load])

  const pendingChanges = activities.filter((activity) => activity.plan_change_status === 'pending')
  const pendingProgress = activities.filter((activity) => activity.progress_review_status === 'pending')
  const blockers = workflow?.execution_blockers || []
  const queue = workflow?.queue

  const runAction = async (path, payload = {}, success) => {
    setSaving(true)
    try {
      const response = await api.post(path, payload)
      message.success(success || response.data.message)
      setModal(null)
      commentForm.resetFields()
      await load()
      onChanged?.()
    } catch (err) {
      const closeErrors = err.response?.data?.errors?.close
      const executionErrors = err.response?.data?.errors?.execution || err.response?.data?.errors?.recommend
      message.error(
        (Array.isArray(closeErrors) && closeErrors[0]) ||
          (Array.isArray(executionErrors) && executionErrors[0]) ||
          err.response?.data?.message ||
          'Action failed',
      )
    } finally {
      setSaving(false)
    }
  }

  const submitCommentModal = () => {
    commentForm.validateFields().then((values) => {
      if (modal === 'return') {
        runAction(`/projects/${projectId}/plan/review`, { decision: 'returned', comment: values.comment })
      } else if (modal === 'recommend') {
        runAction(`/projects/${projectId}/recommend`, { comment: values.comment }, 'Coordinator recommendation recorded')
      } else if (modal === 'signoff') {
        runAction(`/projects/${projectId}/approve-execution`, { comment: values.comment }, 'DICT execution sign-off recorded')
      }
    })
  }

  if (loading && !workflow) {
    return (
      <div className="flex min-h-[200px] items-center justify-center">
        <Spin size="large" />
      </div>
    )
  }

  const showPlan = Boolean(workflow?.can_review_plan) || queue === 'plan_review'
  const showRecommend = Boolean(workflow?.can_recommend) || queue === 'recommendation'
  const showSignOff = Boolean(workflow?.can_sign_off_execution) || queue === 'execution_sign_off'
  const showClosure = queue === 'closure_sign_off' || Boolean(workflow?.can_approve_closure)
  const showProgress = pendingProgress.length > 0
  const showChanges = pendingChanges.length > 0
  const showBlockers = blockers.length > 0

  const hasAny =
    showPlan ||
    showRecommend ||
    showSignOff ||
    showClosure ||
    showProgress ||
    showChanges ||
    showBlockers

  return (
    <div>
      {!hasAny && (
        <Alert
          type="info"
          showIcon
          message="Nothing pending review on this project right now."
          description={projectName ? `${projectName} has no open supervisor actions.` : undefined}
        />
      )}

      {showProgress && (
        <Section title="Progress updates">
          <DataTable
            rowKey="id"
            data={pendingProgress}
            hideSearch
            columns={[
              { title: 'Activity', dataIndex: 'name' },
              {
                title: 'Actual Start',
                dataIndex: 'actual_start_date',
                width: 130,
                render: (value) => (value ? dayjs(value).format('MMM D, YYYY') : '—'),
              },
              {
                title: 'Actual End',
                dataIndex: 'actual_end_date',
                width: 130,
                render: (value) => (value ? dayjs(value).format('MMM D, YYYY') : '—'),
              },
              {
                title: 'Action',
                width: 200,
                render: (_, record) => (
                  <RoleGuard allow={[ROLES.PRV, ROLES.PAD]}>
                    <Space>
                      <Button
                        size="small"
                        type="primary"
                        style={PRIMARY_BTN}
                        loading={saving}
                        onClick={() => runAction(`/activities/${record.id}/progress-review/approve`)}
                      >
                        Approve
                      </Button>
                      <Button
                        size="small"
                        danger
                        loading={saving}
                        onClick={() =>
                          runAction(`/activities/${record.id}/progress-review/reject`, { comment: 'Rejected' })
                        }
                      >
                        Reject
                      </Button>
                    </Space>
                  </RoleGuard>
                ),
              },
            ]}
          />
        </Section>
      )}

      {showChanges && (
        <Section title="Plan changes">
          <DataTable
            rowKey="id"
            data={pendingChanges}
            hideSearch
            columns={[
              { title: 'Activity', dataIndex: 'name' },
              {
                title: 'Proposed Change',
                render: (_, record) => JSON.stringify(record.pending_changes || {}),
              },
              {
                title: 'Action',
                width: 200,
                render: (_, record) => (
                  <RoleGuard allow={[ROLES.PRV, ROLES.PAD]}>
                    <Space>
                      <Button
                        size="small"
                        type="primary"
                        style={PRIMARY_BTN}
                        loading={saving}
                        onClick={() => runAction(`/activities/${record.id}/plan-changes/approve`)}
                      >
                        Approve
                      </Button>
                      <Button
                        size="small"
                        danger
                        loading={saving}
                        onClick={() =>
                          runAction(`/activities/${record.id}/plan-changes/reject`, { comment: 'Rejected' })
                        }
                      >
                        Reject
                      </Button>
                    </Space>
                  </RoleGuard>
                ),
              },
            ]}
          />
        </Section>
      )}

      {showPlan && (
        <Section title="Implementation plan review">
          <Text type="secondary" className="mb-3 block">
            Approve the plan for the next stage, or return it to the planner with comments.
          </Text>
          <RoleGuard allow={[ROLES.PRV, ROLES.PAD]}>
            <Space wrap>
              <Button
                type="primary"
                style={PRIMARY_BTN}
                loading={saving}
                disabled={!workflow?.can_review_plan}
                onClick={() => runAction(`/projects/${projectId}/plan/review`, { decision: 'approved' })}
              >
                Approve plan
              </Button>
              <Button disabled={!workflow?.can_review_plan} onClick={() => setModal('return')}>
                Return with comments
              </Button>
            </Space>
          </RoleGuard>
          <AwaitingRoleNotice activeRoleName={activeRoleName} allow={[ROLES.PRV, ROLES.PAD]} />
          {workflow?.plan_review_comment && (
            <Alert className="mt-3" type="warning" showIcon message="Latest return comment" description={workflow.plan_review_comment} />
          )}
        </Section>
      )}

      {showRecommend && (
        <Section title="Coordinator recommendation">
          <RoleGuard allow={[ROLES.PCO, ROLES.PAD]}>
            <Button
              type="primary"
              style={PRIMARY_BTN}
              loading={saving}
              disabled={!hasPermission(user, 'projects.recommend') || !workflow?.can_recommend}
              onClick={() => setModal('recommend')}
            >
              Recommend execution
            </Button>
          </RoleGuard>
          <AwaitingRoleNotice activeRoleName={activeRoleName} allow={[ROLES.PCO, ROLES.PAD]} />
        </Section>
      )}

      {showSignOff && (
        <Section title="DICT execution sign-off">
          {showBlockers && (
            <Alert
              className="mb-3"
              type="warning"
              showIcon
              message="Execution gates"
              description={
                <ul className="mb-0 list-disc pl-5">
                  {blockers.map((item) => (
                    <li key={item}>{item}</li>
                  ))}
                </ul>
              }
            />
          )}
          <RoleGuard allow={[ROLES.PAP, ROLES.PAD]}>
            <Button
              type="primary"
              style={PRIMARY_BTN}
              loading={saving}
              disabled={!hasPermission(user, 'projects.approve') || !workflow?.can_sign_off_execution}
              onClick={() => setModal('signoff')}
            >
              Sign off execution
            </Button>
          </RoleGuard>
          <AwaitingRoleNotice activeRoleName={activeRoleName} allow={[ROLES.PAP, ROLES.PAD]} />
        </Section>
      )}

      {showBlockers && !showSignOff && (
        <Section title="Blockers">
          <Alert
            type="warning"
            showIcon
            message="Execution gates"
            description={
              <ul className="mb-0 list-disc pl-5">
                {blockers.map((item) => (
                  <li key={item}>{item}</li>
                ))}
              </ul>
            }
          />
        </Section>
      )}

      {showClosure && (
        <Section title="Project closure">
          <ClosurePanel projectId={projectId} closure={closure} workflow={workflow} onChanged={load} />
        </Section>
      )}

      <Modal
        title={
          {
            return: 'Return plan with comments',
            recommend: 'Coordinator recommendation',
            signoff: 'DICT execution sign-off',
          }[modal] || 'Comment'
        }
        open={modal !== null}
        confirmLoading={saving}
        onCancel={() => {
          setModal(null)
          commentForm.resetFields()
        }}
        footer={
          <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8 }}>
            <Button type="primary" loading={saving} onClick={submitCommentModal} style={PRIMARY_BTN}>
              OK
            </Button>
            <Button
              onClick={() => {
                setModal(null)
                commentForm.resetFields()
              }}
            >
              Cancel
            </Button>
          </div>
        }
      >
        <Form form={commentForm} layout="vertical">
          <Form.Item
            name="comment"
            label="Comment"
            rules={modal === 'return' ? [{ required: true, message: 'A return comment is required' }] : []}
          >
            <Input.TextArea rows={3} />
          </Form.Item>
        </Form>
      </Modal>
    </div>
  )
}

export default function ReviewWorkspaceDrawer({ open, project, onClose, onCompleted }) {
  return (
    <Modal
      title={project ? <span style={{ color: MAROON, fontWeight: 800 }}>Project: {project.name}</span> : null}
      open={open}
      onCancel={onClose}
      destroyOnHidden
      width={MODAL_WIDTH.lg}
      className="pms-modal-lg"
      centered
      footer={
        <div style={{ display: 'flex', justifyContent: 'flex-end' }}>
          <Button onClick={onClose}>Close</Button>
        </div>
      }
    >
      {project && (
        <>
          {(project.workflow?.queue || project.queue) && (
            <Text type="secondary" className="mb-4 block text-sm">
              Queue: {String(project.workflow?.queue || project.queue).replaceAll('_', ' ')}
            </Text>
          )}

          <ReviewWorkspacePanel
            projectId={project.id}
            projectName={project.name}
            onChanged={onCompleted}
          />
        </>
      )}
    </Modal>
  )
}
