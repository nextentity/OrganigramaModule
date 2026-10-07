import { Injectable } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { BehaviorSubject, Observable, map, tap, catchError, throwError } from 'rxjs';
import {
  ApiResponse,
  Client,
  DeletionStrategy,
  OrganigramaResponse,
  UserInput,
  UserListResponse,
  UserView,
} from '../models/organigrama.model';

@Injectable({
  providedIn: 'root',
})
export class OrganigramaService {
  private readonly apiUrl = '/api';

  private readonly selectedClientSubject = new BehaviorSubject<Client | null>(null);
  public selectedClient$ = this.selectedClientSubject.asObservable();

  private readonly clientsSubject = new BehaviorSubject<Client[]>([]);
  public clients$ = this.clientsSubject.asObservable();

  private readonly loadingSubject = new BehaviorSubject<boolean>(false);
  public loading$ = this.loadingSubject.asObservable();

  constructor(private http: HttpClient) {}

  public get selectedClient(): Client | null {
    return this.selectedClientSubject.value;
  }

  public setSelectedClient(client: Client | null): void {
    this.selectedClientSubject.next(client);
  }

  /**
   * Carga la lista de empresas disponibles.
   */
  public loadClients(): Observable<Client[]> {
    this.loadingSubject.next(true);
    return this.http.get<ApiResponse<Client[]>>(`${this.apiUrl}/clients`).pipe(
      map((res) => res.data),
      tap((clients) => {
        this.clientsSubject.next(clients);
        if (clients.length > 0 && !this.selectedClientSubject.value) {
          this.setSelectedClient(clients[0]);
        }
        this.loadingSubject.next(false);
      }),
      catchError((err) => {
        this.loadingSubject.next(false);
        return throwError(() => err);
      })
    );
  }

  /**
   * Obtiene el árbol del organigrama y métricas para el cliente activo.
   */
  public getOrganigrama(clientId: number): Observable<OrganigramaResponse> {
    this.loadingSubject.next(true);
    return this.http.get<ApiResponse<OrganigramaResponse>>(`${this.apiUrl}/clients/${clientId}/organigrama`).pipe(
      map((res) => res.data),
      tap(() => this.loadingSubject.next(false)),
      catchError((err) => {
        this.loadingSubject.next(false);
        return throwError(() => err);
      })
    );
  }

  /**
   * Obtiene la lista de personal para la tabla ABM ordenada por nivel jerárquico.
   */
  public getUsers(clientId: number): Observable<UserListResponse> {
    this.loadingSubject.next(true);
    return this.http.get<ApiResponse<UserListResponse>>(`${this.apiUrl}/clients/${clientId}/users`).pipe(
      map((res) => res.data),
      tap(() => this.loadingSubject.next(false)),
      catchError((err) => {
        this.loadingSubject.next(false);
        return throwError(() => err);
      })
    );
  }

  /**
   * Crea un nuevo usuario.
   */
  public createUser(clientId: number, input: UserInput): Observable<UserView> {
    return this.http.post<ApiResponse<UserView>>(`${this.apiUrl}/clients/${clientId}/users`, input).pipe(
      map((res) => res.data)
    );
  }

  /**
   * Actualiza un usuario existente.
   */
  public updateUser(clientId: number, userId: number, input: UserInput): Observable<UserView> {
    return this.http.put<ApiResponse<UserView>>(`${this.apiUrl}/clients/${clientId}/users/${userId}`, input).pipe(
      map((res) => res.data)
    );
  }

  /**
   * Elimina un usuario con estrategia de reasignación de subordinados.
   */
  public deleteUser(
    clientId: number,
    userId: number,
    strategy: DeletionStrategy = DeletionStrategy.ReassignToGrandparent,
    reassignToUserId?: number | null
  ): Observable<any> {
    return this.http.delete<ApiResponse<any>>(`${this.apiUrl}/clients/${clientId}/users/${userId}`, {
      body: {
        strategy,
        reassign_to_user_id: reassignToUserId,
      },
    }).pipe(map((res) => res.data));
  }

  /**
   * Restablece los datos de prueba.
   */
  public resetSeed(): Observable<any> {
    this.loadingSubject.next(true);
    return this.http.post<ApiResponse<any>>(`${this.apiUrl}/seed/reset`, {}).pipe(
      map((res) => res.data),
      tap(() => this.loadingSubject.next(false)),
      catchError((err) => {
        this.loadingSubject.next(false);
        return throwError(() => err);
      })
    );
  }
}

