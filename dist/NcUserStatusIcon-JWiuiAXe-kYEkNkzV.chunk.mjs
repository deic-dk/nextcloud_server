import{c as T}from"./index-fL-TphvA.chunk.mjs";import{g as p}from"./index-BgmsSgl5.chunk.mjs";import{r as n,a7 as q,m as g,_ as h,t as s,a as w}from"./createElementId-DhjFt1I9-BAkC9VVz.chunk.mjs";import{l as f}from"./logger-D3RVzcfQ-Cfvuce7g.chunk.mjs";import{o as x,af as m,al as y,R as b,j as D,H as B,i as I,D as S,e as o}from"./runtime-dom.esm-bundler-BOreokUv.chunk.mjs";const P=`<!--
  - SPDX-FileCopyrightText: 2020 Google Inc.
  - SPDX-License-Identifier: Apache-2.0
-->
<svg viewBox="0 -960 960 960" width="24px" height="24px" xmlns="http://www.w3.org/2000/svg">
	<path
		fill="var(--user-status-color-away, var(--color-warning, #C88800))"
		d="m612-292 56-56-148-148v-184h-80v216l172 172ZM480-80q-83 0-156-31.5T197-197q-54-54-85.5-127T80-480q0-83 31.5-156T197-763q54-54 127-85.5T480-880q83 0 156 31.5T763-763q54 54 85.5 127T880-480q0 83-31.5 156T763-197q-54 54-127 85.5T480-80Z"/>
</svg>
`,X=`<!--
  - SPDX-FileCopyrightText: 2020 Google Inc.
  - SPDX-License-Identifier: Apache-2.0
-->
<svg viewBox="0 -960 960 960" width="24px" height="24px" xmlns="http://www.w3.org/2000/svg">
	<path
		fill="var(--user-status-color-busy, var(--color-error, #DB0606))"
		d="M480-80q-83 0-156-31.5T197-197q-54-54-85.5-127T80-480q0-83 31.5-156T197-763q54-54 127-85.5T480-880q83 0 156 31.5T763-763q54 54 85.5 127T880-480q0 83-31.5 156T763-197q-54 54-127 85.5T480-80Z"/>
</svg>
`,Z=`<!--
  - SPDX-FileCopyrightText: 2020 Google Inc.
  - SPDX-License-Identifier: Apache-2.0
-->
<svg viewBox="0 -960 960 960" width="24px" height="24px" xmlns="http://www.w3.org/2000/svg">
	<path
		fill="var(--user-status-color-busy, var(--color-error, #DB0606))"
		d="M280-440h400v-80H280v80ZM480-80q-83 0-156-31.5T197-197q-54-54-85.5-127T80-480q0-83 31.5-156T197-763q54-54 127-85.5T480-880q83 0 156 31.5T763-763q54 54 85.5 127T880-480q0 83-31.5 156T763-197q-54 54-127 85.5T480-80Z"/>
</svg>
`,u=`<!--
  - SPDX-FileCopyrightText: 2020 Google Inc.
  - SPDX-License-Identifier: Apache-2.0
-->
<svg viewBox="0 -960 960 960" width="24px" height="24px" xmlns="http://www.w3.org/2000/svg">
	<path
		fill="var(--user-status-color-offline, var(--color-text-maxcontrast, #6B6B6B))"
		d="M480-80q-83 0-156-31.5T197-197q-54-54-85.5-127T80-480q0-83 31.5-156T197-763q54-54 127-85.5T480-880q83 0 156 31.5T763-763q54 54 85.5 127T880-480q0 83-31.5 156T763-197q-54 54-127 85.5T480-80Zm0-80q134 0 227-93t93-227q0-134-93-227t-227-93q-134 0-227 93t-93 227q0 134 93 227t227 93Zm0-320Z"/>
</svg>
`,M=`<!--
  - SPDX-FileCopyrightText: 2020 Google Inc.
  - SPDX-License-Identifier: Apache-2.0
-->
<svg viewBox="0 -960 960 960" width="24px" height="24px" xmlns="http://www.w3.org/2000/svg">
	<path
		fill="var(--user-status-color-online, var(--color-success, #2D7B41))"
		d="m424-296 282-282-56-56-226 226-114-114-56 56 170 170Zm56 216q-83 0-156-31.5T197-197q-54-54-85.5-127T80-480q0-83 31.5-156T197-763q54-54 127-85.5T480-880q83 0 156 31.5T763-763q54 54 85.5 127T880-480q0 83-31.5 156T763-197q-54 54-127 85.5T480-80Z"/>
</svg>
`;n(q),n(g);function H(a){switch(a){case"away":return s("away");case"busy":return s("busy");case"dnd":return s("do not disturb");case"online":return s("online");case"invisible":return s("invisible");case"offline":return s("offline");default:return a}}const L=["aria-hidden","aria-label","innerHTML"],_=x({__name:"NcUserStatusIcon",props:S({user:{default:void 0},ariaHidden:{type:[Boolean,String],default:!1}},{status:{},statusModifiers:{}}),emits:["update:status"],setup(a){const e=m(a,"status"),i=a,v=o(()=>e.value&&["invisible","offline"].includes(e.value)),l=o(()=>e.value&&(!i.ariaHidden||i.ariaHidden==="false")?s("User status: {status}",{status:H(e.value)}):void 0);y(()=>i.user,async r=>{if(!e.value&&r&&p()?.user_status?.enabled)try{const{data:t}=await T.get(w("/apps/user_status/api/v1/statuses/{user}",{user:r}));e.value=t.ocs?.data?.status}catch(t){f.debug("Error while fetching user status",{error:t})}},{immediate:!0});const c={online:M,away:P,busy:X,dnd:Z,invisible:u,offline:u},d=o(()=>e.value&&c[e.value]);return(r,t)=>e.value?(b(),D("span",{key:0,class:B(["user-status-icon",{"user-status-icon--invisible":v.value}]),"aria-hidden":!l.value||void 0,"aria-label":l.value,role:"img",innerHTML:d.value},null,10,L)):I("",!0)}}),U=h(_,[["__scopeId","data-v-881a79fb"]]);export{U as N,H as g};
//# sourceMappingURL=NcUserStatusIcon-JWiuiAXe-kYEkNkzV.chunk.mjs.map
