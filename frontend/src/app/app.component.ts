import { Component, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { RouterModule, RouterLink, RouterLinkActive, RouterOutlet } from '@angular/router';

import { ToastModule } from 'primeng/toast';
import { DropdownModule } from 'primeng/dropdown';
import { MessageService } from 'primeng/api';

import { OrganigramaService } from './services/organigrama.service';
import { Client } from './models/organigrama.model';

@Component({
  selector: 'app-root',
  standalone: true,
  imports: [
    CommonModule,
    FormsModule,
    RouterModule,
    RouterLink,
    RouterLinkActive,
    RouterOutlet,
    ToastModule,
    DropdownModule,
  ],
  templateUrl: './app.component.html',
  styleUrls: ['./app.component.scss'],
})
export class AppComponent implements OnInit {
  public clients: Client[] = [];
  public selectedClient: Client | null = null;

  public navLinks = [
    {
      path: '/organigrama',
      label: 'Ver Organigrama',
      icon: 'pi pi-sitemap',
      badge: 'Visual',
    },
    {
      path: '/usuarios',
      label: 'ABM de Personal',
      icon: 'pi pi-users',
      badge: 'Gestión',
    },
  ];

  constructor(
    public organigramaService: OrganigramaService,
    private messageService: MessageService
  ) {}

  ngOnInit(): void {
    this.organigramaService.loadClients().subscribe({
      next: (clients) => {
        this.clients = clients;
        if (clients.length > 0) {
          this.selectedClient = clients[0];
          this.organigramaService.setSelectedClient(clients[0]);
        }
      },
      error: () => {
        this.messageService.add({
          severity: 'error',
          summary: 'Error de Conexión',
          detail: 'No se pudieron recuperar las empresas del servidor.',
        });
      },
    });

    this.organigramaService.selectedClient$.subscribe((client) => {
      this.selectedClient = client;
    });
  }

  public onClientChange(event: { value: Client }): void {
    const client = event.value;
    this.selectedClient = client;
    this.organigramaService.setSelectedClient(client);
  }
}
