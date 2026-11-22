export interface User { id: number; name: string }
export interface Project { id: number; name: string; description: string; archived: boolean; owner_id: number; task_count: number; members: User[] }
export type TaskStatus = 'open' | 'in_progress' | 'blocked' | 'done';
export interface Task { id: number; project_id: number; title: string; description: string; status: TaskStatus; assignee_id: number | null; due_at: string | null; updated_at: string; version: number }
export interface Comment { id: number; task_id: number; user_id: number; body: string; created_at: string; author: string }
export type HelpRequestStatus = 'open' | 'resolved';
export interface HelpRequest {
  id: number; task_id: number; user_id: number; reason: string; created_at: string;
  status: HelpRequestStatus; resolved_at: string | null; resolved_by: number | null;
  author: string; resolver: string | null;
}
export interface AttentionItem {
  task_id: number; project_id: number; project_name: string; title: string;
  assignee_id: number | null; assignee_name: string | null; due_at: string | null;
  status: TaskStatus; priority: number; reasons: string[]; open_requests: HelpRequest[];
}
export interface HelpRequestQueueItem extends HelpRequest {
  project_id: number; project_name: string; task_title: string; task_status: TaskStatus;
}
export interface TaskDetail extends Task { comments: Comment[]; help_requests: HelpRequest[] }
export interface Context { user: User; users: User[]; server_now: string; seeded_at: string }
