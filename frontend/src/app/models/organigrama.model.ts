export interface Client {
  id: number;
  client_hash: string;
  name: string;
  description: string | null;
  created: string;
}

export interface UserView {
  id: number;
  user_hash: string;
  client_id: number;
  user_id: number | null;
  supervisor_name: string | null;
  email: string;
  name: string;
  last_name: string;
  full_name: string;
  identification_number: number;
  identification_code: number | null;
  block_login: boolean;
  level: number;
  direct_reports: number;
  total_reports: number;
  created: string;
  modified: string | null;
}

export interface UserInput {
  client_id?: number;
  email: string;
  name: string;
  last_name: string;
  identification_number: number;
  identification_code?: number | null;
  user_id?: number | null;
  block_login?: boolean;
}

export interface OrgChartMetrics {
  total_users: number;
  hierarchy_depth: number;
  managers: number;
  individual_contributors: number;
  average_span_of_control: number;
  max_span_of_control: number;
  blocked_users: number;
  active_users: number;
  users_per_level: Array<{ level: number; count: number }>;
}

export interface TreeNodeData extends UserView {}

export interface OrgTreeNode {
  key: string;
  label: string;
  type: string;
  styleClass: string;
  expanded: boolean;
  level: number;
  data: TreeNodeData;
  children?: OrgTreeNode[];
}

export interface OrganigramaResponse {
  client: Client;
  metrics: OrgChartMetrics;
  nodes: OrgTreeNode[];
}

export interface UserListResponse {
  client: Client;
  users: UserView[];
}

export interface ApiResponse<T> {
  status: 'success' | 'error';
  data: T;
  message?: string;
  details?: Record<string, string>;
  timestamp: string;
}

export enum DeletionStrategy {
  ReassignToGrandparent = 'reassign_to_grandparent',
  ReassignToSpecificUser = 'reassign_to_specific',
}

