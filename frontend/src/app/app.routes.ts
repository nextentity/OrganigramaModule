import { Routes } from '@angular/router';
import { OrgChartComponent } from './components/org-chart/org-chart.component';
import { UserAbmComponent } from './components/user-abm/user-abm.component';

export const routes: Routes = [
  {
    path: '',
    redirectTo: 'organigrama',
    pathMatch: 'full',
  },
  {
    path: 'organigrama',
    component: OrgChartComponent,
    title: 'Organigrama Jerárquico | Gestión Multi-Tenant',
  },
  {
    path: 'usuarios',
    component: UserAbmComponent,
    title: 'Gestión de Personal | ABM de Usuarios',
  },
  {
    path: '**',
    redirectTo: 'organigrama',
  },
];

