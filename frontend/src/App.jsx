import { lazy, Suspense } from 'react'
import { BrowserRouter as Router, Routes, Route, Navigate, useLocation } from 'react-router-dom'
import { Spin } from 'antd'
import { AuthProvider, useAuth } from './context/AuthContext'

import Login from './pages/login'
import AppLayout from './layouts/AppLayout'
import { pathAllowedForRole } from './layouts/nav'

const ProjectDetailPage = lazy(() => import('./pages/ProjectDetailPage'))
const ReviewsPage = lazy(() => import('./pages/ReviewsPage'))
const UserManagementPage = lazy(() => import('./pages/UserManagementPage'))
const AuditLogPage = lazy(() => import('./pages/AuditLogPage'))
const GenerateReportPage = lazy(() => import('./pages/GenerateReportPage'))
const NotificationsPage = lazy(() => import('./pages/NotificationsPage'))
const LegacyProjectRedirect = lazy(() => import('./pages/LegacyProjectRedirect'))

function PageFallback() {
  return (
    <div className="flex min-h-[40vh] items-center justify-center">
      <Spin size="large" />
    </div>
  )
}

function PublicOnly({ children }) {
  const { user, isLoadingAuth } = useAuth()
  if (isLoadingAuth) return null
  if (user) return <Navigate to="/" replace />
  return children
}

function ProtectedRoute({ children }) {
  const { user, isLoadingAuth } = useAuth()
  if (isLoadingAuth) return null
  if (!user) return <Navigate to="/login" replace />
  return children
}

function RolePageRoute({ children }) {
  const location = useLocation()
  const { user, activeRole, isLoadingAuth } = useAuth()
  if (isLoadingAuth) return null
  const roleName = activeRole?.name ?? user?.role ?? null
  if (!pathAllowedForRole(location.pathname, roleName)) {
    return <Navigate to="/" replace />
  }
  return children
}

export default function App() {
  return (
    <AuthProvider>
      <Router>
        <Suspense fallback={<PageFallback />}>
          <Routes>
            <Route
              path="/login"
              element={
                <PublicOnly>
                  <Login />
                </PublicOnly>
              }
            />
            <Route
              element={
                <ProtectedRoute>
                  <AppLayout />
                </ProtectedRoute>
              }
            >
              <Route path="/" element={<DashboardPage />} />
              <Route path="/home" element={<Navigate to="/" replace />} />
              <Route path="/projects" element={<ProjectsPage />} />
              <Route
                path="/review-project"
                element={
                  <RolePageRoute>
                    <ProjectsPage mode="review" />
                  </RolePageRoute>
                }
              />
              <Route path="/projects/:id" element={<ProjectDetailPage />} />
              <Route
                path="/implementation-plan"
                element={
                  <RolePageRoute>
                    <LegacyProjectRedirect tab="plan" />
                  </RolePageRoute>
                }
              />
              <Route
                path="/traceability-matrix"
                element={
                  <RolePageRoute>
                    <LegacyProjectRedirect tab="rtm" />
                  </RolePageRoute>
                }
              />
              <Route
                path="/documents"
                element={
                  <RolePageRoute>
                    <LegacyProjectRedirect tab="documents" />
                  </RolePageRoute>
                }
              />
              <Route
                path="/reviews"
                element={
                  <RolePageRoute>
                    <ReviewsPage />
                  </RolePageRoute>
                }
              />
              <Route
                path="/recommendations"
                element={
                  <RolePageRoute>
                    <ReviewsPage queueFilter="recommendation" />
                  </RolePageRoute>
                }
              />
              <Route
                path="/reports"
                element={
                  <RolePageRoute>
                    <GenerateReportPage />
                  </RolePageRoute>
                }
              />
              <Route path="/notifications" element={<NotificationsPage />} />
              <Route
                path="/user-management"
                element={
                  <RolePageRoute>
                    <UserManagementPage />
                  </RolePageRoute>
                }
              />
              <Route
                path="/audit-log"
                element={
                  <RolePageRoute>
                    <AuditLogPage />
                  </RolePageRoute>
                }
              />
              <Route
                path="/role-management"
                element={
                  <RolePageRoute>
                    <UserManagementPage />
                  </RolePageRoute>
                }
              />
              <Route
                path="/password-reset"
                element={
                  <RolePageRoute>
                    <UserManagementPage />
                  </RolePageRoute>
                }
              />
            </Route>

            <Route path="*" element={<Navigate to="/login" replace />} />
          </Routes>
        </Suspense>
      </Router>
    </AuthProvider>
  )
}
const DashboardPage = lazy(() => import('./pages/DashboardPage'))
const ProjectsPage = lazy(() => import('./pages/ProjectsPage'))
