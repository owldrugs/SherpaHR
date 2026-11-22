import { Component, inject, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { Api } from './api';
import { AttentionItem, Comment, Context, HelpRequest, HelpRequestQueueItem, Project, Task, TaskDetail, TaskStatus } from './models';

@Component({
  selector: 'app-root',
  standalone: true,
  imports: [CommonModule, FormsModule],
  templateUrl: './app.html',
})
export class AppComponent {
  api = inject(Api);
  context = signal<Context | null>(null);
  projects = signal<Project[]>([]);
  project = signal<Project | null>(null);
  tasks = signal<Task[]>([]);
  detail = signal<TaskDetail | null>(null);
  helpRequests = signal<HelpRequestQueueItem[]>([]);
  attention = signal<AttentionItem[]>([]);
  helpReason = '';
  loading = signal(false);
  saving = signal(false);
  error = signal('');
  notice = signal('');
  status = '';
  search = '';
  comment = '';
  creating = signal(false);
  draft = this.emptyDraft();
  private generation = 0;
  private detailGeneration = 0;
  readonly statuses: { value: TaskStatus; label: string }[] = [
    { value: 'open', label: 'Открыта' }, { value: 'in_progress', label: 'В работе' },
    { value: 'blocked', label: 'Заблокирована' }, { value: 'done', label: 'Готово' },
  ];

  constructor() { void this.load(); }

  async load(): Promise<void> {
    const generation = ++this.generation;
    this.loading.set(true);
    this.error.set('');
    this.closeDetail();
    try {
      const [context, projects, helpRequests, attention] = await Promise.all([
        this.api.request<Context>('/context'),
        this.api.request<Project[]>('/projects'),
        this.api.request<HelpRequestQueueItem[]>('/help-requests'),
        this.api.request<AttentionItem[]>('/attention'),
      ]);
      if (generation !== this.generation) return;
      this.context.set(context);
      this.projects.set(projects);
      this.helpRequests.set(helpRequests);
      this.attention.set(attention);
      const project = projects.find(p => p.id === this.project()?.id) ?? projects[0];
      if (project) await this.selectProject(project);
    } catch (error) {
      if (generation === this.generation) this.fail(error);
    } finally {
      if (generation === this.generation) this.loading.set(false);
    }
  }

  switchUser(value: string): void {
    this.api.userId = Number(value);
    this.project.set(null);
    this.projects.set([]);
    this.tasks.set([]);
    this.context.set(null);
    this.helpRequests.set([]);
    this.attention.set([]);
    this.helpReason = '';
    this.notice.set('');
    void this.load();
  }

  async selectProject(project: Project): Promise<void> {
    const generation = ++this.generation;
    this.loading.set(true);
    this.error.set('');
    this.notice.set('');
    this.project.set(project);
    this.tasks.set([]);
    this.status = '';
    this.search = '';
    this.closeDetail();
    try {
      const tasks = await this.api.request<Task[]>(`/projects/${project.id}/tasks`);
      if (generation === this.generation) this.tasks.set(tasks);
    } catch (error) {
      if (generation === this.generation) this.fail(error);
    } finally {
      if (generation === this.generation) this.loading.set(false);
    }
  }

  filteredTasks(): Task[] {
    return this.tasks().filter(task => (!this.status || task.status === this.status)
      && task.title.toLocaleLowerCase('ru').includes(this.search.toLocaleLowerCase('ru')));
  }

  async openTask(task: Task): Promise<void> {
    const generation = ++this.detailGeneration;
    this.error.set('');
    this.creating.set(false);
    this.detail.set(null);
    try {
      const detail = await this.api.request<TaskDetail>(`/tasks/${task.id}`);
      if (generation !== this.detailGeneration) return;
      this.detail.set(detail);
      this.comment = '';
      this.helpReason = '';
      this.draft = {
        title: detail.title, description: detail.description, status: detail.status,
        assignee_id: detail.assignee_id,
        due_at: detail.due_at ? detail.due_at.slice(0, 16) : '',
      };
    } catch (error) {
      if (generation === this.detailGeneration) this.fail(error);
    }
  }

  closeDetail(): void {
    ++this.detailGeneration;
    this.detail.set(null);
    this.creating.set(false);
    this.comment = '';
    this.helpReason = '';
  }

  createTask(): void {
    this.closeDetail();
    this.draft = this.emptyDraft();
    this.creating.set(true);
  }

  async saveTask(): Promise<void> {
    const project = this.project();
    if (!project || this.saving()) return;
    const userId = this.api.userId;
    const generation = this.detailGeneration;
    const existing = this.detail();
    this.saving.set(true);
    this.error.set('');
    try {
      const data = {
        ...this.draft,
        due_at: this.draft.due_at ? `${this.draft.due_at}:00Z` : null,
        ...(existing ? { version: existing.version } : {}),
      };
      const task = await this.api.request<Task>(existing ? `/tasks/${existing.id}` : `/projects/${project.id}/tasks`, existing ? 'PATCH' : 'POST', data);
      if (userId !== this.api.userId || this.project()?.id !== project.id) return;
      if (!existing) this.projects.update(list => list.map(p => p.id === project.id ? { ...p, task_count: p.task_count + 1 } : p));
      this.tasks.update(list => existing ? list.map(t => t.id === task.id ? task : t) : [...list, task]);
      if (generation === this.detailGeneration) {
        // Saving fields must not erase a comment the user has not sent yet.
        this.detail.set({ ...task, comments: existing?.comments ?? [], help_requests: existing?.help_requests ?? [] });
        this.creating.set(false);
        this.draft = {
          title: task.title, description: task.description, status: task.status,
          assignee_id: task.assignee_id, due_at: task.due_at?.slice(0, 16) ?? '',
        };
        void this.refreshAttention();
        this.notice.set('Задача сохранена');
      }
    } catch (error) { if (generation === this.detailGeneration) this.fail(error); }
    finally { this.saving.set(false); }
  }

  async addComment(): Promise<void> {
    const task = this.detail();
    if (!task || this.saving()) return;
    const generation = this.detailGeneration;
    this.saving.set(true);
    this.error.set('');
    try {
      const comment = await this.api.request<Comment>(`/tasks/${task.id}/comments`, 'POST', { body: this.comment });
      if (generation !== this.detailGeneration) return;
      // Refresh only the discussion: fields may contain unsaved user edits.
      this.detail.update(current => current ? { ...current, comments: [...current.comments, comment] } : current);
      this.comment = '';
      this.notice.set('Комментарий добавлен');
    } catch (error) { if (generation === this.detailGeneration) this.fail(error); }
    finally { this.saving.set(false); }
  }

  async openAttentionItem(item: AttentionItem): Promise<void> {
    const project = this.projects().find(p => p.id === item.project_id);
    if (!project) return;
    if (this.project()?.id !== project.id) await this.selectProject(project);
    const task = this.tasks().find(t => t.id === item.task_id);
    if (task) await this.openTask(task);
  }

  async createHelpRequest(): Promise<void> {
    const task = this.detail();
    if (!task || this.saving() || !this.helpReason.trim()) return;
    const generation = this.detailGeneration;
    this.saving.set(true);
    this.error.set('');
    try {
      const request = await this.api.request<HelpRequest>(`/tasks/${task.id}/help-requests`, 'POST', { reason: this.helpReason });
      if (generation !== this.detailGeneration) return;
      this.detail.update(current => current ? { ...current, help_requests: [request, ...current.help_requests] } : current);
      this.helpReason = '';
      await this.refreshHelpRequests();
      await this.refreshAttention();
      this.notice.set('Запрос на помощь создан');
    } catch (error) { if (generation === this.detailGeneration) this.fail(error); }
    finally { this.saving.set(false); }
  }

  async resolveHelpRequest(request: HelpRequest): Promise<void> {
    if (this.saving()) return;
    const generation = this.detailGeneration;
    this.saving.set(true);
    this.error.set('');
    try {
      const resolved = await this.api.request<HelpRequest>(`/help-requests/${request.id}/resolve`, 'POST');
      if (generation !== this.detailGeneration) return;
      this.detail.update(current => current ? {
        ...current,
        help_requests: current.help_requests.map(item => item.id === resolved.id ? resolved : item),
      } : current);
      await this.refreshHelpRequests();
      await this.refreshAttention();
      this.notice.set('Запрос на помощь отмечен решённым');
    } catch (error) { if (generation === this.detailGeneration) this.fail(error); }
    finally { this.saving.set(false); }
  }

  async refreshAttention(): Promise<void> {
    try {
      this.attention.set(await this.api.request<AttentionItem[]>('/attention'));
    } catch (error) { this.fail(error); }
  }

  async refreshHelpRequests(): Promise<void> {
    try {
      this.helpRequests.set(await this.api.request<HelpRequestQueueItem[]>('/help-requests'));
    } catch (error) { this.fail(error); }
  }

  hasOpenHelpRequest(task: TaskDetail): boolean {
    return task.help_requests.some(request => request.status === 'open');
  }

  reasonLabel(reason: string): string {
    if (reason === 'open_request') return 'Запрос помощи';
    if (reason === 'blocked') return 'Заблокирована';
    if (reason === 'overdue') return 'Просрочена';
    if (reason === 'today') return 'Срок сегодня';
    if (reason === 'next_2_days') return 'Срок в ближайшие 2 дня';
    return reason;
  }

  statusLabel(status: string): string { return this.statuses.find(s => s.value === status)?.label ?? status; }
  userName(id: number | null): string { return this.context()?.users.find(u => u.id === id)?.name ?? 'Не назначен'; }
  private fail(error: unknown): void { this.error.set(error instanceof Error ? error.message : 'Не удалось выполнить запрос'); }
  private emptyDraft(): { title: string; description: string; status: TaskStatus; assignee_id: number | null; due_at: string } {
    return { title: '', description: '', status: 'open', assignee_id: null, due_at: '' };
  }
}
