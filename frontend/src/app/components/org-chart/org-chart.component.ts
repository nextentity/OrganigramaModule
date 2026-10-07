import { Component, OnInit, OnDestroy } from '@angular/core';
import { CommonModule } from '@angular/common';
import { Subscription } from 'rxjs';
import { MessageService, TreeNode } from 'primeng/api';

import { OrganizationChartModule } from 'primeng/organizationchart';
import { ButtonModule } from 'primeng/button';
import { TagModule } from 'primeng/tag';
import { TooltipModule } from 'primeng/tooltip';
import { ProgressSpinnerModule } from 'primeng/progressspinner';

import { OrganigramaService } from '../../services/organigrama.service';
import { Client, OrgChartMetrics, OrgTreeNode, UserView } from '../../models/organigrama.model';

@Component({
  selector: 'app-org-chart',
  standalone: true,
  imports: [
    CommonModule,
    OrganizationChartModule,
    ButtonModule,
    TagModule,
    TooltipModule,
    ProgressSpinnerModule,
  ],
  templateUrl: './org-chart.component.html',
  styleUrls: ['./org-chart.component.scss'],
})
export class OrgChartComponent implements OnInit, OnDestroy {
  public selectedClient: Client | null = null;
  public metrics: OrgChartMetrics | null = null;
  public chartData: TreeNode[] = [];
  public loading: boolean = false;
  public today: Date = new Date();

  private sub: Subscription = new Subscription();

  constructor(
    private organigramaService: OrganigramaService,
    private messageService: MessageService
  ) {}

  ngOnInit(): void {
    this.sub.add(
      this.organigramaService.selectedClient$.subscribe((client) => {
        this.selectedClient = client;
        if (client) {
          this.loadOrganigrama();
        } else {
          this.chartData = [];
          this.metrics = null;
        }
      })
    );
  }

  ngOnDestroy(): void {
    this.sub.unsubscribe();
  }

  public loadOrganigrama(): void {
    if (!this.selectedClient) return;

    this.loading = true;
    this.organigramaService.getOrganigrama(this.selectedClient.id).subscribe({
      next: (res) => {
        this.metrics = res.metrics;
        this.chartData = res.nodes as TreeNode[];
        this.loading = false;
        this.today = new Date();
      },
      error: (err) => {
        this.loading = false;
        this.messageService.add({
          severity: 'error',
          summary: 'Error',
          detail: 'No se pudo cargar el organigrama: ' + (err.error?.message || err.message),
        });
      },
    });
  }

  /**
   * Dispara el diálogo nativo de impresión del navegador.
   * La vista está configurada con @media print para mostrar únicamente el organigrama y el título.
   */
  public printChart(): void {
    if (!this.chartData || this.chartData.length === 0) {
      this.messageService.add({
        severity: 'warn',
        summary: 'Atención',
        detail: 'No hay organigrama cargado para imprimir.',
      });
      return;
    }
    window.print();
  }

  /**
   * Exporta únicamente la estructura jerárquica en formato JSON descargable.
   */
  public exportHierarchyJson(): void {
    if (!this.chartData || this.chartData.length === 0 || !this.selectedClient) {
      this.messageService.add({
        severity: 'warn',
        summary: 'Atención',
        detail: 'No hay datos de organigrama para exportar.',
      });
      return;
    }

    const cleanedTree = this.cleanNodesForExport(this.chartData as OrgTreeNode[]);

    const exportPayload = {
      empresa: this.selectedClient.name,
      exportado_el: new Date().toISOString(),
      metricas_resumen: this.metrics,
      estructura_jerarquica: cleanedTree,
    };

    const dataStr = 'data:text/json;charset=utf-8,' + encodeURIComponent(JSON.stringify(exportPayload, null, 2));
    const downloadAnchor = document.createElement('a');
    downloadAnchor.setAttribute('href', dataStr);
    downloadAnchor.setAttribute('download', `organigrama_${this.selectedClient.name.toLowerCase().replace(/\s+/g, '_')}.json`);
    document.body.appendChild(downloadAnchor);
    downloadAnchor.click();
    downloadAnchor.remove();

    this.messageService.add({
      severity: 'success',
      summary: 'Exportado',
      detail: 'La estructura jerárquica fue exportada exitosamente en JSON.',
    });
  }

  public getAvatarUrl(user: UserView): string {
    // Pravatar URL determinística basada en el ID
    return `https://i.pravatar.cc/150?u=${user.id}`;
  }

  public onAvatarError(event: Event, user: UserView): void {
    const imgElement = event.target as HTMLImageElement;
    // Fallback elegante en caso de offline: SVG inline con iniciales
    const initials = this.getInitials(user.name, user.last_name);
    const svg = `
      <svg xmlns="http://www.w3.org/2000/svg" width="100" height="100" viewBox="0 0 100 100">
        <rect width="100" height="100" fill="#e2e8f0"/>
        <text x="50" y="58" font-family="sans-serif" font-size="36" font-weight="bold" fill="#475569" text-anchor="middle" dominant-baseline="middle">${initials}</text>
      </svg>
    `.trim();
    imgElement.src = `data:image/svg+xml;utf8,${encodeURIComponent(svg)}`;
  }

  public getRoleTitle(user: UserView): string {
    if (user.level === 1) {
      return 'Director General / CEO';
    }
    if (user.level === 2) {
      return user.direct_reports > 0 ? 'Director de Área' : 'Gerente Senior';
    }
    if (user.level === 3) {
      return user.direct_reports > 0 ? 'Líder de Equipo' : 'Especialista Senior';
    }
    return 'Colaborador';
  }

  public getInitials(name: string, lastName: string): string {
    const first = name ? name.charAt(0).toUpperCase() : '';
    const last = lastName ? lastName.charAt(0).toUpperCase() : '';
    return `${first}${last}`;
  }

  private cleanNodesForExport(nodes: OrgTreeNode[]): any[] {
    return nodes.map((node) => ({
      id: node.data.id,
      nombre_completo: node.data.full_name,
      cargo: this.getRoleTitle(node.data),
      dni: node.data.identification_number,
      email: node.data.email,
      nivel: node.data.level,
      jefe_directo: node.data.supervisor_name,
      subordinados_directos: node.data.direct_reports,
      subordinados_totales: node.data.total_reports,
      subordinados: node.children && node.children.length > 0 ? this.cleanNodesForExport(node.children) : [],
    }));
  }
}
