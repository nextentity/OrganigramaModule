import { Component, OnInit, OnDestroy } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule, ReactiveFormsModule, FormBuilder, FormGroup, Validators } from '@angular/forms';
import { Subscription } from 'rxjs';
import { MessageService } from 'primeng/api';

import { TableModule, Table } from 'primeng/table';
import { ButtonModule } from 'primeng/button';
import { DialogModule } from 'primeng/dialog';
import { DropdownModule } from 'primeng/dropdown';
import { InputTextModule } from 'primeng/inputtext';
import { InputNumberModule } from 'primeng/inputnumber';
import { InputSwitchModule } from 'primeng/inputswitch';
import { TagModule } from 'primeng/tag';
import { TooltipModule } from 'primeng/tooltip';
import { RadioButtonModule } from 'primeng/radiobutton';

import { OrganigramaService } from '../../services/organigrama.service';
import { Client, DeletionStrategy, UserInput, UserView } from '../../models/organigrama.model';

@Component({
  selector: 'app-user-abm',
  standalone: true,
  imports: [
    CommonModule,
    FormsModule,
    ReactiveFormsModule,
    TableModule,
    ButtonModule,
    DialogModule,
    DropdownModule,
    InputTextModule,
    InputNumberModule,
    InputSwitchModule,
    TagModule,
    TooltipModule,
    RadioButtonModule,
  ],
  templateUrl: './user-abm.component.html',
  styleUrls: ['./user-abm.component.scss'],
})
export class UserAbmComponent implements OnInit, OnDestroy {
  public users: UserView[] = [];
  public selectedClient: Client | null = null;
  public loading: boolean = false;
  public globalFilter: string = '';

  // Modal Crear / Editar
  public displayUserModal: boolean = false;
  public isEditing: boolean = false;
  public editingUserId: number | null = null;
  public userForm!: FormGroup;

  // Lista de posibles supervisores para el dropdown
  public availableSupervisors: Array<{ label: string; value: number | null }> = [];

  // Modal de Eliminación con Reasignación
  public displayDeleteModal: boolean = false;
  public userToDelete: UserView | null = null;
  public deletionStrategy: DeletionStrategy = DeletionStrategy.ReassignToGrandparent;
  public reassignToUserId: number | null = null;
  public eligibleReassignTargetUsers: Array<{ label: string; value: number }> = [];

  private sub: Subscription = new Subscription();

  constructor(
    private organigramaService: OrganigramaService,
    private fb: FormBuilder,
    private messageService: MessageService
  ) {
    this.initForm();
  }

  ngOnInit(): void {
    this.sub.add(
      this.organigramaService.selectedClient$.subscribe((client) => {
        this.selectedClient = client;
        if (client) {
          this.loadUsers();
        } else {
          this.users = [];
        }
      })
    );
  }

  ngOnDestroy(): void {
    this.sub.unsubscribe();
  }

  public loadUsers(): void {
    if (!this.selectedClient) return;

    this.loading = true;
    this.organigramaService.getUsers(this.selectedClient.id).subscribe({
      next: (res) => {
        this.users = res.users;
        this.loading = false;
      },
      error: (err) => {
        this.loading = false;
        this.messageService.add({
          severity: 'error',
          summary: 'Error',
          detail: 'No se pudieron cargar los usuarios: ' + (err.error?.message || err.message),
        });
      },
    });
  }

  public openNewUserModal(): void {
    if (!this.selectedClient) return;

    this.isEditing = false;
    this.editingUserId = null;
    this.userForm.reset({
      name: '',
      last_name: '',
      email: '',
      identification_number: null,
      identification_code: null,
      user_id: null,
      block_login: false,
    });

    this.updateAvailableSupervisors();
    this.displayUserModal = true;
  }

  public openEditUserModal(userView: UserView): void {
    this.isEditing = true;
    this.editingUserId = userView.id;

    this.userForm.setValue({
      name: userView.name,
      last_name: userView.last_name,
      email: userView.email,
      identification_number: userView.identification_number,
      identification_code: userView.identification_code,
      user_id: userView.user_id,
      block_login: userView.block_login,
    });

    this.updateAvailableSupervisors(userView.id);
    this.displayUserModal = true;
  }

  public saveUser(): void {
    if (this.userForm.invalid || !this.selectedClient) {
      this.userForm.markAllAsTouched();
      return;
    }

    const formVal = this.userForm.value;
    const input: UserInput = {
      client_id: this.selectedClient.id,
      name: formVal.name.trim(),
      last_name: formVal.last_name.trim(),
      email: formVal.email.trim(),
      identification_number: Number(formVal.identification_number),
      identification_code: formVal.identification_code ? Number(formVal.identification_code) : null,
      user_id: formVal.user_id !== null ? Number(formVal.user_id) : null,
      block_login: Boolean(formVal.block_login),
    };

    if (this.isEditing && this.editingUserId) {
      if (input.user_id === this.editingUserId) {
        this.messageService.add({
          severity: 'error',
          summary: 'Operación Inválida',
          detail: 'Un usuario no puede seleccionarse a sí mismo como su propio jefe.',
        });
        return;
      }

      this.organigramaService.updateUser(this.selectedClient.id, this.editingUserId, input).subscribe({
        next: (updatedUser) => {
          this.messageService.add({
            severity: 'success',
            summary: 'Éxito',
            detail: `Usuario ${updatedUser.full_name} actualizado correctamente.`,
          });
          this.displayUserModal = false;
          this.loadUsers();
        },
        error: (err) => this.handleApiError(err),
      });
    } else {
      this.organigramaService.createUser(this.selectedClient.id, input).subscribe({
        next: (newUser) => {
          this.messageService.add({
            severity: 'success',
            summary: 'Éxito',
            detail: `Usuario ${newUser.full_name} creado correctamente.`,
          });
          this.displayUserModal = false;
          this.loadUsers();
        },
        error: (err) => this.handleApiError(err),
      });
    }
  }

  public confirmDeleteUser(userView: UserView): void {
    if (this.users.length <= 1) {
      this.messageService.add({
        severity: 'warn',
        summary: 'Operación no permitida',
        detail: 'No se puede eliminar el único usuario registrado de la empresa.',
      });
      return;
    }

    this.userToDelete = userView;
    this.deletionStrategy = DeletionStrategy.ReassignToGrandparent;
    this.reassignToUserId = null;

    // Identificar subordinados para mostrar badge de promoción automática en el selector
    this.eligibleReassignTargetUsers = this.users
      .filter((u) => u.id !== userView.id)
      .map((u) => {
        const isDirectSub = u.user_id === userView.id;
        const tag = isDirectSub ? ' [Subordinado - Promoción Automática]' : '';
        return {
          label: `${u.full_name} (Nivel ${u.level})${tag}`,
          value: u.id,
        };
      });

    this.displayDeleteModal = true;
  }

  public executeDeleteUser(): void {
    if (!this.selectedClient || !this.userToDelete) return;

    const hasSubordinates = this.userToDelete.direct_reports > 0 || this.userToDelete.user_id === null;

    if (
      hasSubordinates &&
      this.deletionStrategy === DeletionStrategy.ReassignToSpecificUser &&
      !this.reassignToUserId
    ) {
      this.messageService.add({
        severity: 'warn',
        summary: 'Atención',
        detail: 'Debe seleccionar un usuario para recibir el equipo y ocupar la posición vacante.',
      });
      return;
    }

    this.organigramaService
      .deleteUser(
        this.selectedClient.id,
        this.userToDelete.id,
        this.deletionStrategy,
        this.reassignToUserId
      )
      .subscribe({
        next: (res) => {
          const detailMsg = res.promoted
            ? `Usuario eliminado y subordinado promovido exitosamente a la posición superior.`
            : `Usuario eliminado correctamente. Subordinados reasignados: ${res.reassigned_subordinates_count}.`;

          this.messageService.add({
            severity: 'success',
            summary: 'Eliminado',
            detail: detailMsg,
          });
          this.displayDeleteModal = false;
          this.userToDelete = null;
          this.loadUsers();
        },
        error: (err) => this.handleApiError(err),
      });
  }

  private updateAvailableSupervisors(excludeUserId?: number): void {
    this.availableSupervisors = [
      { label: 'Sin Jefe (Nivel 1 / CEO)', value: null },
      ...this.users
        .filter((u) => u.id !== excludeUserId)
        .map((u) => ({
          label: `${u.full_name} (Nivel ${u.level} - ${u.email})`,
          value: u.id,
        })),
    ];
  }

  private initForm(): void {
    this.userForm = this.fb.group({
      name: ['', [Validators.required, Validators.maxLength(100)]],
      last_name: ['', [Validators.required, Validators.maxLength(100)]],
      email: ['', [Validators.required, Validators.email]],
      identification_number: [null, [Validators.required, Validators.min(1)]],
      identification_code: [null],
      user_id: [null],
      block_login: [false],
    });
  }

  private handleApiError(err: any): void {
    const errorMsg = err.error?.message || err.message || 'Error al procesar la solicitud';
    const details = err.error?.details ? Object.values(err.error.details).join(' | ') : '';

    this.messageService.add({
      severity: 'error',
      summary: 'Error de Validación / Negocio',
      detail: `${errorMsg} ${details}`,
      life: 5000,
    });
  }

  public onGlobalFilter(table: Table, event: Event): void {
    const input = event.target as HTMLInputElement;
    table.filterGlobal(input.value, 'contains');
  }

  public formatDni(dni: number | null | undefined): string {
    if (dni === null || dni === undefined || isNaN(Number(dni))) {
      return '';
    }
    return dni.toString().replace(/\B(?=(\d{3})+(?!\d))/g, '.');
  }

  public getAvatarUrl(user: UserView): string {
    return `https://i.pravatar.cc/100?u=${user.id}`;
  }

  public onAvatarError(event: Event, user: UserView): void {
    const imgElement = event.target as HTMLImageElement;
    const initials = (user.name?.charAt(0) || '') + (user.last_name?.charAt(0) || '');
    const svg = `
      <svg xmlns="http://www.w3.org/2000/svg" width="100" height="100" viewBox="0 0 100 100">
        <rect width="100" height="100" fill="#e2e8f0"/>
        <text x="50" y="58" font-family="sans-serif" font-size="36" font-weight="bold" fill="#475569" text-anchor="middle" dominant-baseline="middle">${initials}</text>
      </svg>
    `.trim();
    imgElement.src = `data:image/svg+xml;utf8,${encodeURIComponent(svg)}`;
  }
}

